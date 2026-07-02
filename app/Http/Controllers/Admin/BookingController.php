<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Enums\BookingOrigin;
use App\Exceptions\InsufficientCreditException;
use App\Exceptions\WalletException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreBookingRequest;
use App\Models\Booking;
use App\Models\Branch;
use App\Models\Member;
use App\Models\Service;
use App\Models\User;
use App\Services\Booking\BookingException;
use App\Services\Booking\BookingService;
use App\Services\Line\MemberNotifier;
use App\Services\Wallet\WalletService;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Admin/Booking — the counter/day-view booking surface (Phase 7,
 * docs/phase7-booking-design.md §6–§8). Owner AND staff (front-desk operators) —
 * gated at the route via `role:owner,staff`.
 *
 * BRANCH SCOPING (§5.5, same discipline as the wallet actions): a STAFF operates on their
 * OWN home branch (`users.branch_id`); an OWNER (branch_id null) is unscoped and
 * may act on any branch. The day-view defaults to the operator's branch; staff
 * cannot view/act on another branch's bookings.
 *
 * Contract for the frontend agent:
 *   - index (GET, Inertia): 'Admin/Bookings/Index' — a branch + date day view with
 *     `bookings` (that day), `availability` (slot grid + remaining), `branches`
 *     (allowed for this operator), `services`, `filters` ({branch_id,date}).
 *   - store (POST): staff books on behalf of a member (created_via=staff).
 *   - checkIn (POST): charges the wallet + completes; an InsufficientCreditException
 *     surfaces as "top up first" (booking stays confirmed), a WalletException as
 *     "service not priced".
 *   - noShow (POST) / cancel (DELETE): status transitions.
 */
class BookingController extends Controller
{
    /**
     * Day view for one branch + date. Defaults the branch to the operator's home
     * branch (owner may pass any `?branch_id=`); defaults the date to today.
     *
     * N+1 guard: the day's bookings eager-load `member:id,name` + `creator:id,name`
     * so each row renders its member and (staff) creator without a query per row
     * (§6.4). Availability comes from the shared BookingService (single grouped
     * count feeds every slot).
     */
    public function index(Request $request, BookingService $bookings): Response
    {
        $user = $request->user();

        // Resolve the target branch: staff are pinned to their home branch; an
        // owner may pass ?branch_id=, else falls back to the first bookable branch.
        $branchId = $this->resolveBranchId($request, $user);

        $date = $this->resolveDate($request);

        $dayBookings = $branchId === null ? [] : $this->dayView($branchId, $date);

        $availability = $branchId === null
            ? []
            : $bookings->availableSlots($branchId, $date);

        return Inertia::render('Admin/Bookings/Index', [
            'bookings' => $dayBookings,
            'availability' => $availability,
            'branches' => $this->allowedBranches($user),
            'services' => $bookings->bookableServices(),
            'filters' => [
                'branch_id' => $branchId,
                'date' => $date->toDateString(),
            ],
        ]);
    }

    /**
     * Staff books on behalf of a member (created_via=staff, created_by_user_id =
     * acting staff). The request has already scoped `branch_id` to the staff's own
     * branch (owner = any) and rejected inactive members. A BookingException is
     * surfaced as a clean Thai error toast — nothing was written.
     */
    public function store(StoreBookingRequest $request, BookingService $bookings, MemberNotifier $notifier): RedirectResponse
    {
        $staff = $request->user();

        /** @var Member $member */
        $member = Member::query()->findOrFail($request->validated('member_id'));

        try {
            $booking = $bookings->create(
                branchId: (int) $request->validated('branch_id'),
                member: $member,
                itemCode: (string) $request->validated('item_code'),
                start: CarbonImmutable::parse((string) $request->validated('scheduled_start')),
                origin: BookingOrigin::Staff,
                createdBy: $staff, // staff-origin — recorded as created_by_user_id
                note: $request->validated('note'),
            );
        } catch (BookingException) {
            Inertia::flash('toast', ['type' => 'error', 'message' => __('จองไม่ได้: ช่วงเวลานี้ไม่ว่างหรือไม่พร้อมจอง')]);

            return back();
        }

        // Best-effort LINE confirmation to the member AFTER the booking committed —
        // queued, never blocks/fails the staff action (no-op if not LINE-linked).
        $notifier->bookingConfirmed($booking);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('จองคิวให้สมาชิกแล้ว')]);

        return back();
    }

    /**
     * Check in a confirmed booking (§7) — charges the wallet then completes, in ONE
     * transaction. On insufficient balance the InsufficientCreditException rolls the
     * whole thing back (the booking STAYS confirmed) and we tell staff to top up
     * first; a WalletException means the booked service has no active price. A
     * BookingException means the row wasn't confirmed (wrong state).
     *
     * Branch guard: staff may only check in bookings at their OWN branch.
     */
    public function checkIn(Request $request, Booking $booking, BookingService $bookings, MemberNotifier $notifier, WalletService $wallet): RedirectResponse
    {
        $staff = $request->user();

        $this->assertOperatorMayActOn($staff, $booking);

        try {
            $bookings->checkIn($booking, $staff);
        } catch (InsufficientCreditException) {
            // Balance below the price — nothing debited, booking still confirmed.
            Inertia::flash('toast', ['type' => 'error', 'message' => __('เครดิตไม่พอ: กรุณาเติมเครดิตก่อนเช็คอิน')]);

            return back();
        } catch (WalletException) {
            // The booked service isn't priced — booking still confirmed.
            Inertia::flash('toast', ['type' => 'error', 'message' => __('เช็คอินไม่ได้: บริการนี้ยังไม่ได้ตั้งราคา')]);

            return back();
        } catch (BookingException) {
            Inertia::flash('toast', ['type' => 'error', 'message' => __('เช็คอินไม่ได้: สถานะคิวไม่ถูกต้อง')]);

            return back();
        }

        // Best-effort service-charge receipt AFTER the check-in committed: the booked
        // service name, the amount charged (its catalog price), and the member's
        // remaining wallet balance (re-read from the money authority). Queued, never
        // blocks/fails the check-in (no-op if the member isn't LINE-linked).
        $member = $booking->member;
        if ($member !== null) {
            $notifier->serviceChargeReceipt(
                $member,
                $booking->item_name,
                $this->servicePrice($booking->item_code),
                $wallet->balance($member),
            );
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => __('เช็คอินและหักเครดิตแล้ว')]);

        return back();
    }

    /**
     * Mark a confirmed booking as no-show (§8). Branch-guarded like check-in.
     */
    public function noShow(Request $request, Booking $booking, BookingService $bookings): RedirectResponse
    {
        $staff = $request->user();

        $this->assertOperatorMayActOn($staff, $booking);

        try {
            $bookings->markNoShow($booking, $staff);
        } catch (BookingException) {
            Inertia::flash('toast', ['type' => 'error', 'message' => __('ทำเครื่องหมายไม่มาไม่ได้: สถานะคิวไม่ถูกต้อง')]);

            return back();
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => __('ทำเครื่องหมายไม่มาแล้ว')]);

        return back();
    }

    /**
     * Staff cancel a booking (cancelled_by_user_id = acting staff). Branch-guarded.
     */
    public function cancel(Request $request, Booking $booking, BookingService $bookings): RedirectResponse
    {
        $staff = $request->user();

        $this->assertOperatorMayActOn($staff, $booking);

        try {
            $bookings->cancel($booking, actor: $staff);
        } catch (BookingException) {
            Inertia::flash('toast', ['type' => 'error', 'message' => __('ยกเลิกไม่ได้: คิวนี้เช็คอินหรือปิดไปแล้ว')]);

            return back();
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => __('ยกเลิกคิวแล้ว')]);

        return back();
    }

    /**
     * The day's bookings for a branch, ordered by slot then id, projected to the
     * admin row shape (includes member + staff creator names + full audit
     * timestamps). Eager-loads member + creator (N+1 guard, §6.4).
     *
     * @return list<array{id:int,member_id:int,member_name:string|null,item_code:string,item_name:string,scheduled_start:string|null,scheduled_end:string|null,status:string,created_via:string,created_by_name:string|null,checked_in_at:string|null,completed_at:string|null,cancelled_at:string|null,note:string|null}>
     */
    private function dayView(int $branchId, CarbonImmutable $date): array
    {
        return Booking::query()
            ->forBranchDay($branchId, $date)
            ->with(['member:id,name', 'creator:id,name'])
            ->get()
            ->map(fn (Booking $b): array => [
                'id' => $b->id,
                'member_id' => $b->member_id,
                'member_name' => $b->member?->name,
                'item_code' => $b->item_code,
                'item_name' => $b->item_name,
                'scheduled_start' => $b->scheduled_start?->toIso8601String(),
                'scheduled_end' => $b->scheduled_end?->toIso8601String(),
                'status' => $b->status->value,
                'created_via' => $b->created_via->value,
                'created_by_name' => $b->creator?->name,
                'checked_in_at' => $b->checked_in_at?->toIso8601String(),
                'completed_at' => $b->completed_at?->toIso8601String(),
                'cancelled_at' => $b->cancelled_at?->toIso8601String(),
                'note' => $b->note,
            ])
            ->all();
    }

    /**
     * Branches this operator may view/act on: an owner sees all bookable branches;
     * a branch-scoped staff sees ONLY their home branch (§5.5). Each carries the
     * slot config for the day-view header.
     *
     * @return list<array{id:int,name:string,slot_length_minutes:int,slot_capacity:int,open_time:string,close_time:string,max_advance_days:int}>
     */
    private function allowedBranches(?User $user): array
    {
        return Branch::query()
            ->where('is_active', true)
            ->whereHas('bookingSetting', fn ($q) => $q->where('is_bookable', true))
            // Staff: pin to their home branch. Owner (branch_id null): all bookable.
            ->when(
                $user !== null && $user->isStaff() && $user->branch_id !== null,
                fn ($q) => $q->where('id', $user->branch_id),
            )
            ->with('bookingSetting')
            ->orderBy('name')
            ->get()
            ->map(fn (Branch $branch): array => [
                'id' => $branch->id,
                'name' => $branch->name,
                'slot_length_minutes' => (int) $branch->bookingSetting->slot_length_minutes,
                'slot_capacity' => (int) $branch->bookingSetting->slot_capacity,
                'open_time' => (string) $branch->bookingSetting->open_time,
                'close_time' => (string) $branch->bookingSetting->close_time,
                'max_advance_days' => (int) $branch->bookingSetting->max_advance_days,
            ])
            ->all();
    }

    /**
     * Resolve the day-view's target branch id. STAFF are pinned to their home
     * branch (any `?branch_id=` is ignored). An OWNER may pass `?branch_id=`; when
     * omitted, default to the first allowed bookable branch (or null if none).
     */
    private function resolveBranchId(Request $request, ?User $user): ?int
    {
        if ($user !== null && $user->isStaff() && $user->branch_id !== null) {
            return $user->branch_id;
        }

        $requested = $request->integer('branch_id');
        if ($requested > 0) {
            return $requested;
        }

        $first = $this->allowedBranches($user);

        return $first === [] ? null : $first[0]['id'];
    }

    /**
     * Resolve the day-view date from `?date=YYYY-MM-DD`; default to today. Invalid
     * input falls back to today.
     */
    private function resolveDate(Request $request): CarbonImmutable
    {
        $raw = (string) $request->query('date', '');

        if ($raw === '') {
            return CarbonImmutable::today();
        }

        try {
            return CarbonImmutable::parse($raw)->startOfDay();
        } catch (\Throwable) {
            return CarbonImmutable::today();
        }
    }

    /**
     * Enforce branch scoping on a single-booking action: a branch-scoped staff may
     * only act on bookings AT their home branch; an owner (branch_id null) is
     * unscoped. 403 otherwise (§5.5).
     */
    private function assertOperatorMayActOn(?User $user, Booking $booking): void
    {
        if ($user !== null && $user->isStaff() && $user->branch_id !== null) {
            abort_unless($booking->branch_id === $user->branch_id, 403);
        }
    }

    /**
     * The baht price of `$itemCode` from the `services` catalog — the amount debited
     * at check-in, for the LINE receipt. A decimal-2 STRING (§5.6); falls back to
     * "0.00" if the service can't be resolved (defensive — a successful check-in
     * means it was priced).
     */
    private function servicePrice(string $itemCode): string
    {
        $price = Service::query()
            ->where('item_code', $itemCode)
            ->value('price');

        return $price !== null ? (string) $price : '0.00';
    }
}
