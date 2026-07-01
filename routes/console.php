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
