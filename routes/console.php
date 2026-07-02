<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Phase 7 (§8): flip elapsed `confirmed` bookings to `no_show` hourly. Idempotent
// + chunked, so overlapping/frequent runs are safe. withoutOverlapping guards a
// slow run from doubling up; runInBackground keeps the scheduler tick snappy.
Schedule::command('bookings:sweep')
    ->hourly()
    ->withoutOverlapping()
    ->runInBackground();

// LINE push drain: Plesk runs NO long-lived queue daemon, so the existing
// per-minute `schedule:run` cron is our worker heartbeat. `--stop-when-empty`
// drains all queued pushes (SendLineMessage) then exits, so it never lingers.
// withoutOverlapping keeps a single drain in flight; a slow batch simply carries
// to the next minute. QUEUE_CONNECTION defaults to `database` (config/queue.php).
Schedule::command('queue:work --stop-when-empty')
    ->everyMinute()
    // 10-min lock TTL: if a drain is ever killed uncleanly (deploy/OOM) the lock
    // self-heals within ~10 min instead of the default 24h, so pushes never stall.
    ->withoutOverlapping(10);

// LINE booking reminders: queue a reminder for confirmed bookings starting within
// 24h that haven't been reminded (idempotent via bookings.reminded_at). HOURLY so
// a booking made <24h out is still reminded promptly. Chunked; runs in background.
Schedule::command('bookings:remind')
    ->hourly()
    ->withoutOverlapping()
    ->runInBackground();

// LINE near-expiry reminders: queue a reminder for active credit lots expiring
// within 7 days that still hold balance (idempotent via credit_lots.expiry_reminded_at).
// Expiry is OFF (all lots ship expires_at=null) so this currently matches nothing —
// harmless. DAILY at 09:00 (a friendly morning nudge, not overnight). Chunked; background.
Schedule::command('members:remind-expiry')
    ->dailyAt('09:00')
    ->withoutOverlapping()
    ->runInBackground();
