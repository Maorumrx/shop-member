<?php

declare(strict_types=1);

namespace App\Http\Controllers\Member;

use App\Enums\BookingOrigin;
use App\Enums\BookingStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Member\StoreBookingRequest;
use App\Models\Booking;
use App\Models\Branch;
use App\Models\Member;
use App\Services\Booking\BookingException;
use App\Services\Booking\BookingService;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Member/Booking — the LINE-LIFF self-booking surface (Phase 7,
 * docs/phase7-booking-design.md §6). Behind `auth:members`; every action is FOR
 * the authenticated member ($request->user('members')) — a member never touches
 * another member's bookings.
 *
 * Contract for the frontend agent (all datetimes ISO8601, all booking rules
 * enforced server-side):
 *   - index (GET, Inertia): 'Member/Booking/Index' with `upcoming`, `recent`,
 *     `branches` (bookable, with slot config), and `services` (picker list).
 *   - availability (GET, JSON): { slots: [...] } for a branch + date — the page
 *     fetches this when the member picks a branch/date, then POSTs a chosen slot.
 *   - store (POST): creates a `confirmed` booking (created_via=member); a
 *     BookingException becomes a clean Thai error toast + redirect back.
 *   - cancel (DELETE): cancels the member's OWN, still-confirmed booking.
 *
 * Redemption does NOT happen here — it runs at staff check-in (§7).
 */
class BookingController extends Controller
{
    /**
     * The member's upcoming (confirmed, future) + recent bookings, plus the picker
     * data (bookable branches with their slot config, and the bookable-services
     * catalog). Rendered through the member LIFF layout.
     *
     * N+1 guard: `branch:id,name` eager-loaded on both lists so each row renders
     * its branch name without a query per row (§6.4). The branches/services picker
     * data are single grouped/scoped queries.
     */
    public function index(Request $request, BookingService $bookings): Response
    {
        /** @var Member $member */
        $member = $request->user('members');

        return Inertia::render('Member/Booking/Index', [
            'upcoming' => $this->upcomingForMember($member->id),
            'recent' => $this->recentForMember($member->id),
            'branches' => $this->bookableBranches(),
            'services' => $bookings->bookableServices(),
        ]);
    }

    /**
     * Availability grid (JSON) for `?branch_id=&date=YYYY-MM-DD`. The LIFF page
     * calls this when the member selects a branch + date; it returns the slot list
     * the picker renders. JSON (not Inertia) because it's an incremental fetch, in
     * the spirit of the member LINE-login JSON endpoint.
     *
     * @return JsonResponse { slots: list<{start,end,remaining,is_full}> }
     */
    public function availability(Request $request, BookingService $bookings): JsonResponse
    {
        $validated = $request->validate([
            'branch_id' => ['required', 'integer', 'exists:branch_booking_settings,branch_id'],
            'date' => ['required', 'date'],
        ]);

        $slots = $bookings->availableSlots(
            branchId: (int) $validated['branch_id'],
            date: CarbonImmutable::parse($validated['date']),
        );

        return response()->json(['slots' => $slots]);
    }

    /**
     * Create a booking for the authenticated member (created_via=member,
     * created_by_user_id=null). A BookingException (branch off / slot full / past /
     * off-grid / duplicate) is surfaced as a clean Thai error toast — nothing was
     * written (the whole transaction rolled back).
     */
    public function store(StoreBookingRequest $request, BookingService $bookings): RedirectResponse
    {
        /** @var Member $member */
        $member = $request->user('members');

        try {
            $bookings->create(
                branchId: (int) $request->validated('branch_id'),
                member: $member,
                itemCode: (string) $request->validated('item_code'),
                start: CarbonImmutable::parse((string) $request->validated('scheduled_start')),
                origin: BookingOrigin::Member,
                createdBy: null, // member self-booking — no users row
                note: $request->validated('note'),
            );
        } catch (BookingException) {
            Inertia::flash('toast', ['type' => 'error', 'message' => __('จองไม่ได้: ช่วงเวลานี้ไม่ว่างหรือไม่พร้อมจอง')]);

            return back();
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => __('จองคิวแล้ว')]);

        return to_route('member.bookings.index');
    }

    /**
     * Cancel the member's OWN, still-confirmed booking (member self-cancel:
     * cancelled_by_user_id stays null). Route-model binding + an ownership check
     * (403 if it isn't theirs) guard access; the service rejects a wrong-state
     * transition (already checked_in/completed/cancelled) as a clean toast.
     */
    public function cancel(Request $request, Booking $booking, BookingService $bookings): RedirectResponse
    {
        /** @var Member $member */
        $member = $request->user('members');

        // Own-only: a member may never cancel someone else's booking.
        abort_unless($booking->member_id === $member->id, 403);

        try {
            $bookings->cancel($booking, actor: null);
        } catch (BookingException) {
            Inertia::flash('toast', ['type' => 'error', 'message' => __('ยกเลิกไม่ได้: คิวนี้เช็คอินหรือปิดไปแล้ว')]);

            return back();
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => __('ยกเลิกคิวแล้ว')]);

        return to_route('member.bookings.index');
    }

    /**
     * The member's upcoming (confirmed, future) bookings, soonest-first, with the
     * branch name eager-loaded (I15 + N+1 guard).
     *
     * @return list<array{id:int,branch_name:string|null,item_code:string,item_name:string,scheduled_start:string|null,scheduled_end:string|null,status:string,note:string|null}>
     */
    private function upcomingForMember(int $memberId): array
    {
        return Booking::query()
            ->where('member_id', $memberId)
            ->upcoming()
            ->with('branch:id,name')
            ->get()
            ->map(fn (Booking $b): array => $this->toMemberRow($b))
            ->all();
    }

    /**
     * The member's recent NON-upcoming bookings (completed / cancelled / no_show /
     * past), newest-first, capped at 20 — the "history" tab. Branch name
     * eager-loaded (N+1 guard).
     *
     * @return list<array{id:int,branch_name:string|null,item_code:string,item_name:string,scheduled_start:string|null,scheduled_end:string|null,status:string,note:string|null}>
     */
    private function recentForMember(int $memberId): array
    {
        return Booking::query()
            ->where('member_id', $memberId)
            // Everything that is NOT an active future reservation: terminal rows,
            // plus any elapsed confirmed the sweep hasn't flipped yet.
            ->where(function ($q): void {
                $q->whereIn('status', [
                    BookingStatus::Completed,
                    BookingStatus::Cancelled,
                    BookingStatus::NoShow,
                ])->orWhere('scheduled_start', '<', now());
            })
            ->with('branch:id,name')
            ->orderByDesc('scheduled_start')
            ->limit(20)
            ->get()
            ->map(fn (Booking $b): array => $this->toMemberRow($b))
            ->all();
    }

    /**
     * Project a booking to the member-facing row shape (no staff/audit fields).
     *
     * @return array{id:int,branch_name:string|null,item_code:string,item_name:string,scheduled_start:string|null,scheduled_end:string|null,status:string,note:string|null}
     */
    private function toMemberRow(Booking $b): array
    {
        return [
            'id' => $b->id,
            'branch_name' => $b->branch?->name,
            'item_code' => $b->item_code,
            'item_name' => $b->item_name,
            'scheduled_start' => $b->scheduled_start?->toIso8601String(),
            'scheduled_end' => $b->scheduled_end?->toIso8601String(),
            'status' => $b->status->value,
            'note' => $b->note,
        ];
    }

    /**
     * Bookable branches for the picker: active branches whose settings row exists
     * and is_bookable=true, each with the slot config the LIFF page needs to show
     * the window/length up front (the authoritative grid still comes from
     * availability()).
     *
     * N+1 guard: eager-load `bookingSetting` in one pass.
     *
     * @return list<array{id:int,name:string,slot_length_minutes:int,open_time:string,close_time:string,max_advance_days:int}>
     */
    private function bookableBranches(): array
    {
        return Branch::query()
            ->where('is_active', true)
            ->whereHas('bookingSetting', fn ($q) => $q->where('is_bookable', true))
            ->with('bookingSetting')
            ->orderBy('name')
            ->get()
            ->map(fn (Branch $branch): array => [
                'id' => $branch->id,
                'name' => $branch->name,
                'slot_length_minutes' => (int) $branch->bookingSetting->slot_length_minutes,
                'open_time' => (string) $branch->bookingSetting->open_time,
                'close_time' => (string) $branch->bookingSetting->close_time,
                'max_advance_days' => (int) $branch->bookingSetting->max_advance_days,
            ])
            ->all();
    }
}
