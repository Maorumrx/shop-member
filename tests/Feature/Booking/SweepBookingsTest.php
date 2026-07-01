<?php

declare(strict_types=1);

// Phase 7 — bookings:sweep (App\Console\Commands\SweepBookings,
// docs/phase7-booking-design.md §8). With v1 AUTO-CONFIRM there is no `pending`,
// so the job runs ONLY the elapsed-`confirmed` → `no_show` pass:
//   - a `confirmed` row whose scheduled_end < now flips to `no_show`;
//   - a FUTURE `confirmed` row (scheduled_end >= now) is left `confirmed`;
//   - terminal rows (`completed` / `cancelled` / already `no_show`) are untouched;
//   - it is IDEMPOTENT: a second run finds nothing and changes nothing.
//
// The job touches ONLY `bookings` (no ledger involvement — an unattended booking
// never held an entitlement). We persist rows directly with explicit scheduled_*
// so "elapsed vs future" is unambiguous, and $this->travelTo() a fixed now.
//
// sqlite caveat: no DB CHECK constraints / concurrency here — the sweep is a plain
// status scan, driver-agnostic. Origin CHECK is MariaDB-only; the rows we build
// keep created_via/created_by consistent anyway (member ⇒ null).

use App\Enums\BookingOrigin;
use App\Enums\BookingStatus;
use App\Models\Booking;
use App\Models\Branch;
use App\Models\Member;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/** Fixed local "now" so elapsed/future windows are deterministic. */
function sweepNow(): CarbonImmutable
{
    return CarbonImmutable::parse('2026-08-03 12:00:00');
}

function sweepBranch(): Branch
{
    return Branch::create(['name' => 'Sweep Branch', 'is_active' => true]);
}

function sweepMember(array $overrides = []): Member
{
    return Member::create(array_merge([
        'name' => 'Sweep Member',
        'phone' => '0870000000',
        'is_active' => true,
    ], $overrides));
}

/**
 * Persist a booking directly with an explicit start + status. scheduled_end is
 * derived (start + 60). member origin ⇒ created_by null (origin CHECK-consistent).
 */
function sweepBooking(Member $member, int $branchId, CarbonImmutable $start, BookingStatus $status): Booking
{
    return Booking::create([
        'member_id' => $member->id,
        'branch_id' => $branchId,
        'item_code' => 'MASSAGE_60',
        'item_name' => 'MASSAGE_60',
        'scheduled_start' => $start,
        'scheduled_end' => $start->addMinutes(60),
        'slot_length_minutes' => 60,
        'status' => $status,
        'created_via' => BookingOrigin::Member,
        'created_by_user_id' => null,
        'note' => null,
    ]);
}

beforeEach(function () {
    $this->travelTo(sweepNow());
});

it('flips an elapsed confirmed booking to no_show', function () {
    $branch = sweepBranch();
    $member = sweepMember();

    // Slot 09:00–10:00 today — fully elapsed by now (12:00).
    $elapsed = sweepBooking($member, $branch->id, sweepNow()->startOfDay()->setTime(9, 0, 0), BookingStatus::Confirmed);

    $this->artisan('bookings:sweep')->assertSuccessful();

    expect($elapsed->fresh()->status)->toBe(BookingStatus::NoShow);
});

it('leaves a future confirmed booking confirmed', function () {
    $branch = sweepBranch();
    $member = sweepMember();

    // Slot 14:00–15:00 today — still in the future relative to now (12:00).
    $future = sweepBooking($member, $branch->id, sweepNow()->startOfDay()->setTime(14, 0, 0), BookingStatus::Confirmed);

    $this->artisan('bookings:sweep')->assertSuccessful();

    expect($future->fresh()->status)->toBe(BookingStatus::Confirmed);
});

it('leaves an in-progress confirmed booking (end still in the future) confirmed', function () {
    $branch = sweepBranch();
    $member = sweepMember();

    // Slot 11:30–12:30 — started but scheduled_end (12:30) is after now (12:00),
    // so it has NOT elapsed and must stay confirmed.
    $inProgress = sweepBooking($member, $branch->id, sweepNow()->startOfDay()->setTime(11, 30, 0), BookingStatus::Confirmed);

    $this->artisan('bookings:sweep')->assertSuccessful();

    expect($inProgress->fresh()->status)->toBe(BookingStatus::Confirmed);
});

it('leaves terminal bookings (completed / cancelled / no_show) untouched', function () {
    $branch = sweepBranch();
    $member = sweepMember();
    $past = sweepNow()->startOfDay()->setTime(9, 0, 0); // elapsed slot for all three

    $completed = sweepBooking($member, $branch->id, $past, BookingStatus::Completed);
    $cancelled = sweepBooking($member, $branch->id, $past, BookingStatus::Cancelled);
    $noShow = sweepBooking($member, $branch->id, $past, BookingStatus::NoShow);

    $this->artisan('bookings:sweep')->assertSuccessful();

    // Only `confirmed` rows are swept — terminal ones never change.
    expect($completed->fresh()->status)->toBe(BookingStatus::Completed);
    expect($cancelled->fresh()->status)->toBe(BookingStatus::Cancelled);
    expect($noShow->fresh()->status)->toBe(BookingStatus::NoShow);
});

it('sweeps a mixed board correctly in one pass', function () {
    $branch = sweepBranch();
    $member = sweepMember();

    $elapsed = sweepBooking($member, $branch->id, sweepNow()->startOfDay()->setTime(9, 0, 0), BookingStatus::Confirmed);
    $future = sweepBooking($member, $branch->id, sweepNow()->startOfDay()->setTime(15, 0, 0), BookingStatus::Confirmed);
    $doneEarlier = sweepBooking($member, $branch->id, sweepNow()->startOfDay()->setTime(8, 0, 0), BookingStatus::Completed);

    $this->artisan('bookings:sweep')->assertSuccessful();

    expect($elapsed->fresh()->status)->toBe(BookingStatus::NoShow);      // flipped
    expect($future->fresh()->status)->toBe(BookingStatus::Confirmed);    // kept
    expect($doneEarlier->fresh()->status)->toBe(BookingStatus::Completed); // kept
});

it('is idempotent — a second run changes nothing', function () {
    $branch = sweepBranch();
    $member = sweepMember();

    $elapsed = sweepBooking($member, $branch->id, sweepNow()->startOfDay()->setTime(9, 0, 0), BookingStatus::Confirmed);

    // First run flips it.
    $this->artisan('bookings:sweep')->assertSuccessful();
    expect($elapsed->fresh()->status)->toBe(BookingStatus::NoShow);

    $updatedAt = $elapsed->fresh()->updated_at;

    // Second run finds nothing confirmed-and-elapsed → no-op (row unchanged).
    $this->artisan('bookings:sweep')->assertSuccessful();

    $again = $elapsed->fresh();
    expect($again->status)->toBe(BookingStatus::NoShow);
    expect($again->updated_at->equalTo($updatedAt))->toBeTrue();
    // Exactly one no_show row exists — the sweep never duplicated or re-touched it.
    expect(Booking::where('status', BookingStatus::NoShow)->count())->toBe(1);
});
