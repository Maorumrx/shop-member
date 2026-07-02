<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\CreditLotStatus;
use App\Models\CreditLot;
use App\Services\Line\MemberNotifier;
use Illuminate\Console\Command;

/**
 * members:remind-expiry — queue a best-effort LINE reminder for each ACTIVE credit
 * lot whose `expires_at` falls within the next 7 days and still holds spendable
 * value, once per lot (the money-wallet reframe of the dropped MemberPackage scan).
 *
 * Targets `status = active` AND `expires_at ∈ (now, now+7d]` (not already past —
 * expired lots are the daily expiry job's concern) AND `expiry_reminded_at IS NULL`,
 * restricted to LINE-linked members and lots with a positive remaining balance
 * (`paid_remaining + bonus_remaining > 0`). Stamps `expiry_reminded_at` when queued,
 * so the command is IDEMPOTENT + safe to run DAILY.
 *
 * IMPORTANT: expiry is OFF (every lot ships with `expires_at = null`), so this scan
 * currently matches NOTHING — that is expected and fine. It is reworked to reference
 * the new `credit_lots` model so it no longer fatals against the dropped
 * `member_packages` table, and works unchanged the day the client enables expiry.
 *
 * CHUNKED via chunkById; sending is deferred to the queue inside {@see MemberNotifier}.
 */
class RemindExpiring extends Command
{
    /**
     * @var string
     */
    protected $signature = 'members:remind-expiry';

    /**
     * @var string
     */
    protected $description = 'Queue LINE reminders for active credit lots expiring within 7 days (idempotent).';

    /**
     * How far ahead (days) an expiry is considered "near" and worth a nudge.
     */
    private const HORIZON_DAYS = 7;

    /**
     * bcmath scale for the remaining-balance sum (§5.6 — money is never float).
     */
    private const SCALE = 2;

    /**
     * Scan the near-expiry, not-yet-reminded, LINE-linked ACTIVE lots that still
     * hold spendable value, queue a reminder for each, and stamp `expiry_reminded_at`.
     * Eager-loads the member so the message needs no per-row query (N+1 guard).
     * Reports the count.
     */
    public function handle(MemberNotifier $notifier): int
    {
        $now = now();
        $until = $now->copy()->addDays(self::HORIZON_DAYS);
        $reminded = 0;

        CreditLot::query()
            ->where('status', CreditLotStatus::Active)
            ->whereNull('expiry_reminded_at')
            ->whereNotNull('expires_at')
            ->where('expires_at', '>', $now)      // not already past
            ->where('expires_at', '<=', $until)   // within the 7-day horizon
            // Only members who linked LINE — never queue an undeliverable push.
            ->whereHas('member', fn ($q) => $q->whereNotNull('line_user_id'))
            // Only lots that still hold spendable value.
            ->whereRaw('paid_remaining + bonus_remaining > 0')
            ->with('member')
            ->chunkById(500, function ($lots) use ($notifier, &$reminded): void {
                foreach ($lots as $lot) {
                    /** @var CreditLot $lot */
                    $remaining = bcadd(
                        (string) $lot->paid_remaining,
                        (string) $lot->bonus_remaining,
                        self::SCALE,
                    );

                    // Defensive: a race could drain the lot between the WHERE and here
                    // — skip (and DON'T stamp) so it isn't wrongly consumed.
                    if (bccomp($remaining, '0', self::SCALE) !== 1) {
                        continue;
                    }

                    // Stamp FIRST so a crash mid-loop can't double-remind next run;
                    // the push itself is best-effort and non-critical.
                    $lot->update(['expiry_reminded_at' => now()]);

                    $notifier->nearExpiry($lot, $remaining);
                    $reminded++;
                }
            });

        $this->info("members:remind-expiry — {$reminded} expiry reminder(s) queued.");

        return self::SUCCESS;
    }
}
