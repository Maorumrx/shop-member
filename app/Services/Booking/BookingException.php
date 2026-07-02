<?php

declare(strict_types=1);

namespace App\Services\Booking;

use App\Models\Booking;
use Carbon\CarbonInterface;
use RuntimeException;

/**
 * Raised by {@see BookingService} when a booking action cannot be honoured
 * (docs/phase7-booking-design.md §5, §8). A DOMAIN exception, distinct from a
 * validation error: the request shape has passed the FormRequest, but a
 * scheduling rule fails — the branch isn't bookable, the slot is off-grid /
 * out-of-window / in the past / beyond the advance horizon, the slot filled
 * under concurrency, the member already holds this exact slot, or the booking
 * is in the wrong state for the requested transition.
 *
 * The slot-capacity guard is checked UNDER the per-branch lock inside a
 * `DB::transaction` (§5.4 Strategy A), so `slotFull()` means the slot was
 * genuinely full at commit time — no booking row was written.
 *
 * Controllers catch this and surface a clean Thai error toast (never a 500),
 * mirroring how {@see \App\Exceptions\WalletException} is handled.
 *
 * @see BookingService
 */
final class BookingException extends RuntimeException
{
    /**
     * The branch takes no online bookings (no settings row, or `is_bookable=false`).
     */
    public static function branchNotBookable(int $branchId): self
    {
        return new self("Branch [{$branchId}] is not bookable.");
    }

    /**
     * The requested slot start is not on the branch's slot grid, or its slot
     * would end after `close_time` / start before `open_time`.
     */
    public static function slotOutsideWindow(int $branchId, CarbonInterface $start): self
    {
        return new self(
            "Slot [{$start->toDateTimeString()}] is outside branch [{$branchId}] open hours or off the slot grid."
        );
    }

    /**
     * The requested slot is in the past (cannot book a slot that already started).
     */
    public static function slotInPast(CarbonInterface $start): self
    {
        return new self("Slot [{$start->toDateTimeString()}] is in the past.");
    }

    /**
     * The requested slot is beyond the branch's `max_advance_days` horizon.
     */
    public static function beyondAdvanceWindow(int $branchId, CarbonInterface $start): self
    {
        return new self(
            "Slot [{$start->toDateTimeString()}] is beyond branch [{$branchId}] max advance window."
        );
    }

    /**
     * The slot has reached `slot_capacity` (checked under the per-branch lock —
     * genuinely full at commit; no row was written) (§5.4).
     */
    public static function slotFull(int $branchId, CarbonInterface $start): self
    {
        return new self(
            "Slot [{$start->toDateTimeString()}] at branch [{$branchId}] is full."
        );
    }

    /**
     * The member already holds a live (confirmed/checked_in) booking for this
     * exact slot at this branch — blocks the double-tap duplicate (§9.4).
     */
    public static function duplicateSlot(int $memberId, int $branchId, CarbonInterface $start): self
    {
        return new self(
            "Member [{$memberId}] already booked slot [{$start->toDateTimeString()}] at branch [{$branchId}]."
        );
    }

    /**
     * The target member is inactive/deactivated — cannot hold a booking (§3.3, §5.4).
     */
    public static function memberInactive(int $memberId): self
    {
        return new self("Member [{$memberId}] is inactive and cannot book.");
    }

    /**
     * The booking is not in the state the requested transition requires
     * (e.g. check-in on a non-confirmed row, cancel after check-in). Terminal
     * states are never resurrected (§4).
     */
    public static function invalidTransition(Booking $booking, string $action): self
    {
        return new self(
            "Booking [{$booking->id}] in status [{$booking->status->value}] cannot [{$action}]."
        );
    }
}
