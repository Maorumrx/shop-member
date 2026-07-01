<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\EntitlementStatus;
use App\Models\MemberPackage;
use App\Services\Line\MemberNotifier;
use Illuminate\Console\Command;

/**
 * members:remind-expiry — queue a best-effort LINE reminder for each ACTIVE lot
 * whose `expires_at` falls within the next 7 days and still holds redeemable
 * units, once per lot.
 *
 * Targets `status = active` AND `expires_at ∈ (now, now+7d]` (not already past —
 * expired lots are the daily expiry job's concern) AND
 * `expiry_reminded_at IS NULL` (rides I9), restricted to LINE-linked members and
 * lots with a positive remaining balance (nothing to nudge about an empty lot).
 * Stamps `expiry_reminded_at` when queued, so the command is IDEMPOTENT + safe to
 * run DAILY: subsequent days skip already-reminded lots.
 *
 * CHUNKED via chunkById; the remaining balance is summed per lot from its active
 * entitlements. Sending is deferred to the queue inside {@see MemberNotifier}.
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
    protected $description = 'Queue LINE reminders for active packages expiring within 7 days (idempotent).';

    /**
     * How far ahead (days) an expiry is considered "near" and worth a nudge.
     */
    private const HORIZON_DAYS = 7;

    /**
     * Scan the near-expiry, not-yet-reminded, LINE-linked ACTIVE lots that still
     * hold balance, queue a reminder for each, and stamp `expiry_reminded_at`.
     * Eager-loads member + the active entitlements so the remaining balance and
     * message need no per-row query (N+1 guard). Reports the count.
     */
    public function handle(MemberNotifier $notifier): int
    {
        $now = now();
        $until = $now->copy()->addDays(self::HORIZON_DAYS);
        $reminded = 0;

        MemberPackage::query()
            ->where('status', EntitlementStatus::Active)
            ->whereNull('expiry_reminded_at')
            ->whereNotNull('expires_at')
            ->where('expires_at', '>', $now)      // not already past
            ->where('expires_at', '<=', $until)   // within the 7-day horizon
            // Only members who linked LINE — never queue an undeliverable push.
            ->whereHas('member', fn ($q) => $q->whereNotNull('line_user_id'))
            // Only lots that still have something to lose.
            ->whereHas('entitlements', fn ($q) => $q
                ->where('status', EntitlementStatus::Active)
                ->where('qty_remaining', '>', 0))
            ->with([
                'member',
                'entitlements' => fn ($q) => $q->where('status', EntitlementStatus::Active),
            ])
            ->chunkById(500, function ($lots) use ($notifier, &$reminded): void {
                foreach ($lots as $lot) {
                    /** @var MemberPackage $lot */
                    $remaining = (int) $lot->entitlements->sum('qty_remaining');

                    // Defensive: a race could empty the lot between the WHERE and
                    // here — skip (and DON'T stamp) so it isn't wrongly consumed.
                    if ($remaining <= 0) {
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
