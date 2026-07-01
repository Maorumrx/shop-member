<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\BookingStatus;
use App\Models\Booking;
use App\Services\Line\MemberNotifier;
use Illuminate\Console\Command;

/**
 * bookings:remind — queue a best-effort LINE reminder for each upcoming booking
 * whose slot starts within the next 24 hours and hasn't been reminded yet.
 *
 * Targets `status = confirmed` AND `scheduled_start ∈ (now, now+24h]` AND
 * `reminded_at IS NULL` (rides I15/I16), restricted to members who actually
 * linked LINE (`members.line_user_id IS NOT NULL`) so we never queue a push
 * that can't land. Stamps `reminded_at` the moment the reminder is queued, which
 * makes the command IDEMPOTENT + safe to run HOURLY: a second run in the same
 * window skips already-reminded rows.
 *
 * CHUNKED via chunkById so a large backlog never loads all rows at once. The
 * actual send is deferred to the queue (SendLineMessage) inside
 * {@see MemberNotifier} — this command only builds the target set + marks it.
 */
class RemindBookings extends Command
{
    /**
     * @var string
     */
    protected $signature = 'bookings:remind';

    /**
     * @var string
     */
    protected $description = 'Queue LINE reminders for confirmed bookings starting within 24h (idempotent).';

    /**
     * Scan the due, not-yet-reminded, LINE-linked confirmed bookings, queue a
     * reminder for each, and stamp `reminded_at`. Eager-loads member + branch so
     * the message builder needs no per-row query (N+1 guard). Reports the count.
     */
    public function handle(MemberNotifier $notifier): int
    {
        $now = now();
        $until = $now->copy()->addDay();
        $reminded = 0;

        Booking::query()
            ->where('status', BookingStatus::Confirmed)
            ->whereNull('reminded_at')
            ->where('scheduled_start', '>', $now)
            ->where('scheduled_start', '<=', $until)
            // Only members who linked LINE — never queue an undeliverable push.
            ->whereHas('member', fn ($q) => $q->whereNotNull('line_user_id'))
            ->with(['member', 'branch:id,name'])
            ->chunkById(500, function ($bookings) use ($notifier, &$reminded): void {
                foreach ($bookings as $booking) {
                    /** @var Booking $booking */
                    // Stamp FIRST so a crash mid-loop can't double-remind on the
                    // next run; the push itself is best-effort and non-critical.
                    $booking->update(['reminded_at' => now()]);

                    $notifier->bookingReminder($booking);
                    $reminded++;
                }
            });

        $this->info("bookings:remind — {$reminded} booking reminder(s) queued.");

        return self::SUCCESS;
    }
}
