<?php

declare(strict_types=1);

namespace App\Services\Booking;

use App\Enums\BookingOrigin;
use App\Enums\BookingStatus;
use App\Enums\ItemType;
use App\Models\Booking;
use App\Models\BranchBookingSetting;
use App\Models\Member;
use App\Models\PackageLine;
use App\Models\User;
use App\Services\Redemption\RedemptionService;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * BookingService — Phase-7 scheduling core (docs/phase7-booking-design.md §5–§8).
 *
 * Owns the slot-grid derivation, availability, concurrency-safe create, the
 * lifecycle transitions (cancel / check-in / no-show), and the bookable-services
 * catalog projection. Slots are DERIVED, not stored (§5.1): a slot is a
 * `scheduled_start` on the grid `open_time, +len, +2·len, …` whose end lands
 * at/before `close_time`. A slot's identity is `(branch_id, scheduled_start)`.
 *
 * CAPACITY-HOLDING statuses are `confirmed` + `checked_in` (§5.2). Concurrency is
 * Strategy A (§5.4): create locks the branch's `branch_booking_settings` row FOR
 * UPDATE, then re-counts the slot against committed data — serialising all
 * concurrent creates for that branch, exactly like the redemption lock discipline.
 *
 * Redemption is NEVER performed here directly; check-in delegates to the existing
 * {@see RedemptionService} (threading the booking id so ledger rows link back, §7).
 *
 * Time note: the app aliases Date to CarbonImmutable
 * (AppServiceProvider::configureDefaults), so `now()`/derived instants are
 * immutable — every add/sub returns a fresh instance (no accidental mutation).
 */
final class BookingService
{
    /**
     * The statuses that occupy a slot's capacity (§5.2). `completed`, `cancelled`
     * and `no_show` never hold a chair; soft-deleted rows are excluded by the
     * SoftDeletes global scope.
     *
     * @var list<BookingStatus>
     */
    private const CAPACITY_HOLDING = [BookingStatus::Confirmed, BookingStatus::CheckedIn];

    public function __construct(
        private readonly RedemptionService $redemptions,
    ) {
    }

    /**
     * Availability grid for one branch on one local date (§5.3).
     *
     * Builds the slot grid from the branch settings (open/close/length) and, for
     * each slot, computes `remaining = slot_capacity − (confirmed+checked_in count
     * at that exact start)`. Past slots for TODAY are omitted; a non-bookable
     * branch (no settings row or `is_bookable=false`) yields an empty array.
     *
     * N+1 guard: ONE grouped count query for the whole day feeds every slot
     * (no per-slot query), via I14 `(branch_id, scheduled_start, status)`.
     *
     * @return list<array{start: string, end: string, remaining: int, is_full: bool}>
     *         ISO8601 `start`/`end`; `remaining` clamped at >= 0; `is_full` = remaining <= 0.
     */
    public function availableSlots(int $branchId, CarbonInterface $date): array
    {
        $settings = $this->bookableSettings($branchId);

        if ($settings === null) {
            return [];
        }

        $day = CarbonImmutable::parse($date)->startOfDay();
        $now = CarbonImmutable::now();

        // One grouped count of capacity-holding bookings for the day → [start => taken].
        $taken = $this->slotCounts($branchId, $day);

        $slots = [];

        foreach ($this->slotGrid($settings, $day) as $start) {
            // Omit past slots for today (a slot that already started can't be booked).
            if ($start->lessThan($now)) {
                continue;
            }

            $end = $start->addMinutes($settings->slot_length_minutes);
            $used = $taken[$start->toDateTimeString()] ?? 0;
            $remaining = max(0, $settings->slot_capacity - $used);

            $slots[] = [
                'start' => $start->toIso8601String(),
                'end' => $end->toIso8601String(),
                'remaining' => $remaining,
                'is_full' => $remaining <= 0,
            ];
        }

        return $slots;
    }

    /**
     * Create a `confirmed` booking (v1 auto-confirm) for `$member` at `$branchId`
     * for the slot starting `$start`, intending `$itemCode` (§5.4, §6).
     *
     * Validates: branch bookable, slot on-grid + inside open hours, within
     * `max_advance_days`, not in the past, member active. The `item_name` is
     * snapshotted from the ACTIVE bookable-services catalog; a code absent there
     * falls back to the code itself (booking is independent of ownership — the
     * member may buy at the counter on arrival, §3.2).
     *
     * CONCURRENCY (§5.4 Strategy A): inside a `DB::transaction`, lock the branch's
     * `branch_booking_settings` row FOR UPDATE, re-count the slot against committed
     * data, and reject (throw {@see BookingException::slotFull()}) if the count has
     * reached capacity — else insert. The same-member duplicate guard runs under
     * the same lock so two concurrent taps can't both land.
     *
     * @param  User|null  $createdBy  Acting staff for a `staff`-origin booking;
     *                                MUST be null for a `member` self-booking
     *                                (the origin/id CHECK enforces this at the DB).
     *
     * @throws BookingException On any scheduling-rule failure (nothing is written).
     */
    public function create(
        int $branchId,
        Member $member,
        string $itemCode,
        CarbonInterface $start,
        BookingOrigin $origin,
        ?User $createdBy = null,
        ?string $note = null,
    ): Booking {
        if (! $member->is_active) {
            throw BookingException::memberInactive($member->id);
        }

        $slotStart = CarbonImmutable::parse($start);

        return DB::transaction(function () use (
            $branchId, $member, $itemCode, $slotStart, $origin, $createdBy, $note
        ): Booking {
            // Per-branch serialize point (§5.4): lock the settings row FOR UPDATE.
            // We must read it anyway for length/capacity, so the lock is free.
            /** @var BranchBookingSetting|null $settings */
            $settings = BranchBookingSetting::query()
                ->where('branch_id', $branchId)
                ->lockForUpdate()
                ->first();

            if ($settings === null || ! $settings->is_bookable) {
                throw BookingException::branchNotBookable($branchId);
            }

            // Rule guards (§5.4 note): all evaluated against the LOCKED config.
            $this->assertSlotBookable($settings, $branchId, $slotStart);

            // Same-member double-book guard (§9.4): app-level, under the lock.
            $memberDup = Booking::query()
                ->where('branch_id', $branchId)
                ->where('member_id', $member->id)
                ->where('scheduled_start', $slotStart)
                ->whereIn('status', self::CAPACITY_HOLDING)
                ->exists();

            if ($memberDup) {
                throw BookingException::duplicateSlot($member->id, $branchId, $slotStart);
            }

            // Committed capacity count (we hold the lock) → reject if full (§5.3).
            $used = Booking::query()
                ->where('branch_id', $branchId)
                ->where('scheduled_start', $slotStart)
                ->whereIn('status', self::CAPACITY_HOLDING)
                ->count();

            if ($used >= $settings->slot_capacity) {
                throw BookingException::slotFull($branchId, $slotStart);
            }

            $end = $slotStart->addMinutes($settings->slot_length_minutes);

            return Booking::create([
                'member_id' => $member->id,
                'branch_id' => $branchId,
                'item_code' => $itemCode,
                // Snapshot the label from the active catalog; fall back to the code.
                'item_name' => $this->resolveItemName($itemCode) ?? $itemCode,
                'scheduled_start' => $slotStart,
                'scheduled_end' => $end,
                'slot_length_minutes' => $settings->slot_length_minutes,
                'status' => BookingStatus::Confirmed, // v1 auto-confirm
                'created_via' => $origin,
                'created_by_user_id' => $createdBy?->id,
                'note' => $note,
            ]);
        });
    }

    /**
     * Cancel a booking (§8). Sets status→cancelled with `cancelled_at` and, for a
     * staff cancel, `cancelled_by_user_id` (null = member self-cancel / system).
     * Only a live `confirmed` booking may be cancelled — a checked_in/completed/
     * cancelled/no_show row is a wrong-state transition (§4). Frees the slot
     * immediately (it leaves the capacity-holding set).
     *
     * @param  User|null  $actor  Staff who cancelled; null for a member self-cancel.
     *
     * @throws BookingException On a wrong-state transition.
     */
    public function cancel(Booking $booking, ?User $actor = null): Booking
    {
        if ($booking->status !== BookingStatus::Confirmed) {
            throw BookingException::invalidTransition($booking, 'cancel');
        }

        $booking->update([
            'status' => BookingStatus::Cancelled,
            'cancelled_at' => now(),
            'cancelled_by_user_id' => $actor?->id,
        ]);

        return $booking;
    }

    /**
     * Check in a `confirmed` booking (§7) — the moment redemption runs.
     *
     * In ONE `DB::transaction`: assert status=confirmed, call
     * {@see RedemptionService::redeem()} for the booking's `item_code` × 1 (scoped
     * to the staff's home branch; owner = null = unscoped, §5.5), threading the
     * booking id so every ledger row it writes carries `booking_id`. On success,
     * settle the row on `completed` (stamping checked_in/completed audit). If
     * `redeem()` throws (insufficient balance), the WHOLE txn rolls back and the
     * booking STAYS confirmed — the error surfaces so staff can sell a package
     * first, then retry.
     *
     * @throws BookingException           When not in `confirmed` state.
     * @throws \App\Exceptions\RedemptionException  When the member has no balance
     *                                    (rolls back — the booking stays confirmed).
     */
    public function checkIn(Booking $booking, User $staff): Booking
    {
        return DB::transaction(function () use ($booking, $staff): Booking {
            if ($booking->status !== BookingStatus::Confirmed) {
                throw BookingException::invalidTransition($booking, 'check_in');
            }

            // Redemption runs here. bookingId stamps the ledger rows (§7). If the
            // member has no balance this throws (RedemptionException) and the txn
            // rolls back — the booking is untouched, still confirmed.
            $this->redemptions->redeem(
                member: $booking->member,
                itemCode: $booking->item_code,
                qty: 1,                       // one slot = one service unit (v1)
                staff: $staff,
                branchId: $staff->branch_id,  // owner => null => unscoped (§5.5)
                bookingId: $booking->id,
            );

            $now = now();

            // v1 collapses checked_in→completed: settle on the terminal success in
            // the same transaction (§7). Stamp both audit legs.
            $booking->update([
                'status' => BookingStatus::Completed,
                'checked_in_at' => $now,
                'checked_in_by_user_id' => $staff->id,
                'completed_at' => $now,
            ]);

            return $booking;
        });
    }

    /**
     * Mark a `confirmed` booking as no-show (§8) — the member never arrived.
     * Staff action OR the `bookings:sweep` job. Only valid from `confirmed`.
     *
     * @throws BookingException On a wrong-state transition.
     */
    public function markNoShow(Booking $booking, ?User $staff = null): Booking
    {
        if ($booking->status !== BookingStatus::Confirmed) {
            throw BookingException::invalidTransition($booking, 'no_show');
        }

        $booking->update(['status' => BookingStatus::NoShow]);

        return $booking;
    }

    /**
     * Distinct bookable SERVICES the frontend picker offers (§ brief).
     *
     * The catalog's services: distinct `(item_code, item_name)` where
     * `item_type = service` across ACTIVE packages' lines. Booking is independent
     * of ownership — a member may book a service they don't yet own and buy it at
     * the counter on arrival (§3.2). Returned ordered by name.
     *
     * N+1 guard: a single grouped query (no hydration per line).
     *
     * @return list<array{item_code: string, item_name: string}>
     */
    public function bookableServices(): array
    {
        return PackageLine::query()
            ->where('item_type', ItemType::Service)
            // Only from packages currently on sale.
            ->whereHas('package', fn ($q) => $q->where('is_active', true))
            ->groupBy('item_code', 'item_name')
            ->orderBy('item_name')
            ->get(['item_code', 'item_name'])
            ->map(fn (PackageLine $line): array => [
                'item_code' => $line->item_code,
                'item_name' => $line->item_name,
            ])
            ->all();
    }

    /**
     * The branch's booking settings IFF the branch is bookable (settings row
     * exists AND `is_bookable=true`); otherwise null. Read-only (no lock) — used
     * by availability. The create path re-reads it under a lock.
     */
    private function bookableSettings(int $branchId): ?BranchBookingSetting
    {
        /** @var BranchBookingSetting|null $settings */
        $settings = BranchBookingSetting::query()
            ->where('branch_id', $branchId)
            ->first();

        if ($settings === null || ! $settings->is_bookable) {
            return null;
        }

        return $settings;
    }

    /**
     * Grouped count of capacity-holding bookings (confirmed+checked_in) for the
     * branch on `$day`, keyed by `scheduled_start` `'Y-m-d H:i:s'` (§5.3 day view).
     * ONE query serves every slot's remaining figure (rides I14).
     *
     * @return array<string, int>
     */
    private function slotCounts(int $branchId, CarbonImmutable $day): array
    {
        $dayStart = $day->startOfDay();
        $dayEnd = $dayStart->addDay();

        return Booking::query()
            ->where('branch_id', $branchId)
            ->where('scheduled_start', '>=', $dayStart)
            ->where('scheduled_start', '<', $dayEnd)
            ->whereIn('status', self::CAPACITY_HOLDING)
            ->groupBy('scheduled_start')
            ->selectRaw('scheduled_start, COUNT(*) AS taken')
            ->get()
            ->mapWithKeys(fn ($row): array => [
                // Normalise to the same 'Y-m-d H:i:s' key the grid emits.
                CarbonImmutable::parse($row->scheduled_start)->toDateTimeString() => (int) $row->taken,
            ])
            ->all();
    }

    /**
     * The slot grid for `$day`: starts at `open_time`, stepping by
     * `slot_length_minutes`, INCLUDING only slots whose END lands at/before
     * `close_time` (§5.1). Returns an ordered collection of CarbonImmutable starts.
     *
     * @return Collection<int, CarbonImmutable>
     */
    private function slotGrid(BranchBookingSetting $settings, CarbonImmutable $day): Collection
    {
        $dayStart = $day->startOfDay();
        $open = $this->composeTime($dayStart, $settings->open_time);
        $close = $this->composeTime($dayStart, $settings->close_time);
        $length = $settings->slot_length_minutes;

        /** @var Collection<int, CarbonImmutable> $grid */
        $grid = collect();
        $cursor = $open;

        // A slot is valid only if its full length fits before close_time.
        while ($cursor->addMinutes($length)->lessThanOrEqualTo($close)) {
            $grid->push($cursor);
            $cursor = $cursor->addMinutes($length);
        }

        return $grid;
    }

    /**
     * Assert the requested `$start` is bookable against the (locked) settings:
     * on the slot grid, inside open hours, not in the past, within the advance
     * window (§5.4, §6). Throws a specific {@see BookingException} otherwise.
     *
     * @throws BookingException
     */
    private function assertSlotBookable(BranchBookingSetting $settings, int $branchId, CarbonImmutable $start): void
    {
        $now = CarbonImmutable::now();

        if ($start->lessThan($now)) {
            throw BookingException::slotInPast($start);
        }

        // Advance horizon: max_advance_days ahead from the start of today (0 = today
        // only). A slot beyond that window is rejected (§3.1, §6).
        $horizon = $now->startOfDay()->addDays($settings->max_advance_days)->endOfDay();
        if ($start->greaterThan($horizon)) {
            throw BookingException::beyondAdvanceWindow($branchId, $start);
        }

        // On-grid + inside window: the start must equal one of the day's grid slots.
        $grid = $this->slotGrid($settings, $start->startOfDay());
        $onGrid = $grid->contains(
            fn (CarbonImmutable $slot): bool => $slot->equalTo($start)
        );

        if (! $onGrid) {
            throw BookingException::slotOutsideWindow($branchId, $start);
        }
    }

    /**
     * Compose a 'H:i:s' TIME value onto the given day's date, yielding a
     * CarbonImmutable at that local wall-clock time (§9 single-TZ assumption).
     */
    private function composeTime(CarbonImmutable $day, string $time): CarbonImmutable
    {
        [$h, $m, $s] = array_pad(array_map('intval', explode(':', $time)), 3, 0);

        return $day->startOfDay()->setTime($h, $m, $s);
    }

    /**
     * Snapshot label for `$itemCode` from the ACTIVE bookable-services catalog
     * (most-recent match). Null when the code isn't an active service — the caller
     * falls back to the code itself (a member may book intent for something not
     * currently in the catalog; §3.2).
     */
    private function resolveItemName(string $itemCode): ?string
    {
        /** @var PackageLine|null $line */
        $line = PackageLine::query()
            ->where('item_code', $itemCode)
            ->where('item_type', ItemType::Service)
            ->whereHas('package', fn ($q) => $q->where('is_active', true))
            ->orderByDesc('id')
            ->first(['item_name']);

        return $line?->item_name;
    }
}
