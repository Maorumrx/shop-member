<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\BookingStatus;
use App\Models\Booking;
use Illuminate\Console\Command;

/**
 * bookings:sweep — flip elapsed `confirmed` bookings to `no_show`
 * (docs/phase7-booking-design.md §8). A member who never arrived leaves a
 * `confirmed` row whose slot has fully passed; this job settles it so the day
 * view and reporting reflect reality.
 *
 * With v1 AUTO-CONFIRM there is NO `pending` status, so the design's
 * "stale pending → cancelled" sweep is gone — this job runs ONLY the elapsed
 * `confirmed` → `no_show` pass.
 *
 * IDEMPOTENT + CHUNKED: it targets exactly `status = confirmed AND scheduled_end
 * < now` (rides I16 `(status, scheduled_end)`), transitions each via the model so
 * the enum cast/soft-delete scope apply, and is safe to run frequently (a
 * second run finds nothing to do). Touches ONLY `bookings` — no ledger
 * involvement, since an unattended booking never held an entitlement (§8).
 */
class SweepBookings extends Command
{
    /**
     * @var string
     */
    protected $signature = 'bookings:sweep';

    /**
     * @var string
     */
    protected $description = 'Flip elapsed confirmed bookings (scheduled_end < now) to no_show (Phase 7 §8).';

    /**
     * Chunk-scan elapsed confirmed bookings and mark them no_show. Uses chunkById
     * so a large backlog never loads all rows at once; the WHERE narrows the scan
     * to I16. Reports the count swept.
     */
    public function handle(): int
    {
        $now = now();
        $swept = 0;

        Booking::query()
            ->where('status', BookingStatus::Confirmed)
            ->where('scheduled_end', '<', $now)
            ->chunkById(500, function ($bookings) use (&$swept): void {
                foreach ($bookings as $booking) {
                    /** @var Booking $booking */
                    $booking->update(['status' => BookingStatus::NoShow]);
                    $swept++;
                }
            });

        $this->info("bookings:sweep — {$swept} confirmed booking(s) flipped to no_show.");

        return self::SUCCESS;
    }
}
