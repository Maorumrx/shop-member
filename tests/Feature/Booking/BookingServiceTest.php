<?php

declare(strict_types=1);

// Phase 7 — BookingService (the จองคิว scheduling core, docs/phase7-booking-design.md
// §5–§8). Calls the service DIRECTLY (no HTTP) to prove the slot-grid + capacity +
// lifecycle contract:
//   - CAPACITY: confirmed+checked_in hold a chair; a slot full at slot_capacity
//     rejects the next create (BookingException) and writes NO row.
//   - VALIDATION: branch not bookable / slot in past / off open-hours / off-grid /
//     beyond max_advance_days / inactive member / same-member same-slot duplicate
//     each throw and write nothing.
//   - HAPPY PATH: item_name snapshot, scheduled_end = start + slot_length, and the
//     origin/created_by_user_id CHECK (member ⇒ null, staff ⇒ user id).
//   - availableSlots: remaining = capacity − (confirmed+checked_in), is_full marking,
//     past-today slots omitted, empty when the branch is not bookable.
//   - checkIn: the MONEY path — redemption runs, ledger rows carry booking_id, the
//     booking settles on `completed`; insufficient balance rolls the WHOLE txn back
//     (booking stays confirmed, ZERO redeem rows, entitlement untouched).
//   - cancel / no_show: cancel frees the slot; markNoShow flips confirmed→no_show.
//
// TIME DETERMINISM: the app aliases Date→CarbonImmutable, so every derived instant
// is immutable. We $this->travelTo() a fixed reference instant well inside open
// hours so "future slot" math and the past-slot omission are stable regardless of
// the wall clock. FUTURE slots are built on the grid via bookingFutureSlot().
//
// sqlite caveat: the DB-level CHECK constraints (chk_bookings_origin, chk_bbs_*)
// and TRUE row-lock concurrency are MariaDB-only and are NOT exercised here (the
// suite runs on sqlite :memory:, whose lockForUpdate compiles to a no-op and which
// skips the guarded CHECK statements). The app-level guards ARE exercised.

use App\Enums\BookingOrigin;
use App\Enums\BookingStatus;
use App\Enums\EntitlementStatus;
use App\Enums\ItemType;
use App\Enums\LedgerReason;
use App\Enums\UserRole;
use App\Exceptions\RedemptionException;
use App\Models\Booking;
use App\Models\Branch;
use App\Models\BranchBookingSetting;
use App\Models\Entitlement;
use App\Models\EntitlementLedger;
use App\Models\Member;
use App\Models\MemberPackage;
use App\Models\User;
use App\Services\Booking\BookingException;
use App\Services\Booking\BookingService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * A fixed local reference instant used by every test: a weekday mid-morning well
 * inside the 09:00–20:00 window and ON the 60-min grid (10:00:00). Travelling here
 * makes "now" deterministic so past/future slot math never flakes on the clock.
 */
function bookingNow(): CarbonImmutable
{
    return CarbonImmutable::parse('2026-08-03 10:00:00'); // Mon 10:00 local
}

/** A branch to host slots. */
function bookingBranch(string $name = 'Bookable Branch'): Branch
{
    return Branch::create(['name' => $name, 'is_active' => true]);
}

/**
 * Mint a bookable BranchBookingSetting: is_bookable on, 60-min slots, wide open
 * hours (09:00–20:00), max_advance_days 30. Override any field via $overrides
 * (e.g. is_bookable false, a tighter window, a shorter advance horizon).
 *
 * @param  array<string, mixed>  $overrides
 */
function bookingSettings(int $branchId, int $capacity = 1, array $overrides = []): BranchBookingSetting
{
    return BranchBookingSetting::create(array_merge([
        'branch_id' => $branchId,
        'is_bookable' => true,
        'slot_capacity' => $capacity,
        'slot_length_minutes' => 60,
        'open_time' => '09:00:00',
        'close_time' => '20:00:00',
        'max_advance_days' => 30,
    ], $overrides));
}

/**
 * A grid-aligned FUTURE slot start, relative to bookingNow(). $daysAhead 0 = today
 * (later than "now"), and $hour is the wall-clock hour on the 60-min grid. Default
 * 14:00 tomorrow — safely future, on-grid, inside open hours, inside the advance
 * window.
 */
function bookingFutureSlot(int $daysAhead = 1, int $hour = 14): CarbonImmutable
{
    return bookingNow()->startOfDay()->addDays($daysAhead)->setTime($hour, 0, 0);
}

/** A plain active member. */
function bookingMember(array $overrides = []): Member
{
    return Member::create(array_merge([
        'name' => 'Booking Customer',
        'phone' => '0850000000',
        'is_active' => true,
    ], $overrides));
}

/** Active, verified staff/owner (the check-in operator; ledger.staff_id). */
function bookingStaff(UserRole $role = UserRole::Staff, ?int $branchId = null): User
{
    $user = User::factory()->create([
        'role' => $role,
        'is_active' => true,
        'email_verified_at' => now(),
    ]);

    if ($branchId !== null) {
        $user->forceFill(['branch_id' => $branchId])->save();
    }

    return $user;
}

/**
 * Mint a single-line redeemable lot for the member so check-in has balance to
 * consume. Mirrors RedemptionEndpointTest::redeemEndpointLot — MemberPackage +
 * Entitlement + opening purchase ledger row.
 */
function bookingLot(Member $member, string $itemCode, int $qty, ?int $branchId = null): MemberPackage
{
    $lot = MemberPackage::create([
        'member_id' => $member->id,
        'package_id' => null,
        'branch_id' => $branchId,
        'purchased_at' => now(),
        'expires_at' => now()->addDays(60),
        'price_paid' => '0.00',
        'status' => EntitlementStatus::Active,
    ]);

    $ent = Entitlement::create([
        'member_package_id' => $lot->id,
        'member_id' => $member->id,
        'item_code' => $itemCode,
        'item_name' => $itemCode,
        'item_type' => ItemType::Service,
        'qty_total' => $qty,
        'qty_remaining' => $qty,
        'redeem_group' => null,
        'expires_at' => now()->addDays(60),
        'status' => EntitlementStatus::Active,
    ]);

    $ent->ledgerEntries()->create([
        'member_id' => $member->id,
        'delta' => $qty,
        'reason' => LedgerReason::Purchase,
        'balance_after' => $qty,
        'booking_id' => null,
        'staff_id' => null,
        'note' => null,
    ]);

    return $lot;
}

beforeEach(function () {
    // Pin "now" so every slot computation (past/future/advance) is deterministic.
    $this->travelTo(bookingNow());
});

// ---------------------------------------------------------------------------
// CAPACITY
// ---------------------------------------------------------------------------

it('fills a slot to capacity then rejects the next create (writes no extra row)', function () {
    $branch = bookingBranch();
    bookingSettings($branch->id, capacity: 2);
    $slot = bookingFutureSlot();

    $m1 = bookingMember(['phone' => '0850000001']);
    $m2 = bookingMember(['phone' => '0850000002']);
    $m3 = bookingMember(['phone' => '0850000003']);

    $b1 = app(BookingService::class)->create($branch->id, $m1, 'MASSAGE_60', $slot, BookingOrigin::Member);
    $b2 = app(BookingService::class)->create($branch->id, $m2, 'MASSAGE_60', $slot, BookingOrigin::Member);

    expect($b1->status)->toBe(BookingStatus::Confirmed);
    expect($b2->status)->toBe(BookingStatus::Confirmed);
    expect(Booking::count())->toBe(2);

    // The THIRD tap into the full slot is rejected — and nothing is written.
    expect(fn () => app(BookingService::class)->create($branch->id, $m3, 'MASSAGE_60', $slot, BookingOrigin::Member))
        ->toThrow(BookingException::class);

    expect(Booking::count())->toBe(2);
    $this->assertDatabaseCount('bookings', 2);
});

// ---------------------------------------------------------------------------
// VALIDATION — each rejected create throws and writes nothing
// ---------------------------------------------------------------------------

it('rejects a create against a non-bookable branch', function () {
    $branch = bookingBranch();
    bookingSettings($branch->id, overrides: ['is_bookable' => false]);
    $member = bookingMember();

    expect(fn () => app(BookingService::class)->create($branch->id, $member, 'MASSAGE_60', bookingFutureSlot(), BookingOrigin::Member))
        ->toThrow(BookingException::class);

    $this->assertDatabaseCount('bookings', 0);
});

it('rejects a create against a branch with no settings row at all', function () {
    $branch = bookingBranch();
    $member = bookingMember();

    expect(fn () => app(BookingService::class)->create($branch->id, $member, 'MASSAGE_60', bookingFutureSlot(), BookingOrigin::Member))
        ->toThrow(BookingException::class);

    $this->assertDatabaseCount('bookings', 0);
});

it('rejects a slot in the past', function () {
    $branch = bookingBranch();
    bookingSettings($branch->id);
    $member = bookingMember();

    // 08:00 today is before "now" (10:00) — a slot that already started.
    $past = bookingNow()->startOfDay()->setTime(8, 0, 0);

    expect(fn () => app(BookingService::class)->create($branch->id, $member, 'MASSAGE_60', $past, BookingOrigin::Member))
        ->toThrow(BookingException::class);

    $this->assertDatabaseCount('bookings', 0);
});

it('rejects a slot outside open–close hours', function () {
    $branch = bookingBranch();
    bookingSettings($branch->id); // 09:00–20:00
    $member = bookingMember();

    // 20:00 tomorrow: a 60-min slot starting here ends at 21:00 > close_time.
    $afterClose = bookingFutureSlot(daysAhead: 1, hour: 20);

    expect(fn () => app(BookingService::class)->create($branch->id, $member, 'MASSAGE_60', $afterClose, BookingOrigin::Member))
        ->toThrow(BookingException::class);

    $this->assertDatabaseCount('bookings', 0);
});

it('rejects an off-grid slot start', function () {
    $branch = bookingBranch();
    bookingSettings($branch->id); // 60-min grid on the hour
    $member = bookingMember();

    // 14:30 tomorrow is not on the :00 grid.
    $offGrid = bookingFutureSlot(daysAhead: 1, hour: 14)->addMinutes(30);

    expect(fn () => app(BookingService::class)->create($branch->id, $member, 'MASSAGE_60', $offGrid, BookingOrigin::Member))
        ->toThrow(BookingException::class);

    $this->assertDatabaseCount('bookings', 0);
});

it('rejects a slot beyond the max_advance_days horizon', function () {
    $branch = bookingBranch();
    bookingSettings($branch->id, overrides: ['max_advance_days' => 7]);
    $member = bookingMember();

    // 10 days out with a 7-day horizon — past the advance window.
    $beyond = bookingFutureSlot(daysAhead: 10, hour: 14);

    expect(fn () => app(BookingService::class)->create($branch->id, $member, 'MASSAGE_60', $beyond, BookingOrigin::Member))
        ->toThrow(BookingException::class);

    $this->assertDatabaseCount('bookings', 0);
});

it('rejects a create for an inactive member', function () {
    $branch = bookingBranch();
    bookingSettings($branch->id);
    $member = bookingMember(['is_active' => false]);

    expect(fn () => app(BookingService::class)->create($branch->id, $member, 'MASSAGE_60', bookingFutureSlot(), BookingOrigin::Member))
        ->toThrow(BookingException::class);

    $this->assertDatabaseCount('bookings', 0);
});

it('rejects the same member double-booking the exact same slot', function () {
    $branch = bookingBranch();
    bookingSettings($branch->id, capacity: 5); // capacity is not the blocker here
    $member = bookingMember();
    $slot = bookingFutureSlot();

    app(BookingService::class)->create($branch->id, $member, 'MASSAGE_60', $slot, BookingOrigin::Member);

    // Same member, same branch, same slot start → duplicate guard fires.
    expect(fn () => app(BookingService::class)->create($branch->id, $member, 'MASSAGE_60', $slot, BookingOrigin::Member))
        ->toThrow(BookingException::class);

    // Only the first row exists (capacity had room; the duplicate guard, not
    // capacity, blocked the second).
    $this->assertDatabaseCount('bookings', 1);
});

// ---------------------------------------------------------------------------
// HAPPY PATH — snapshot + derived end + origin CHECK
// ---------------------------------------------------------------------------

it('creates a confirmed member booking: snapshots item_name, derives end, created_by null', function () {
    $branch = bookingBranch();
    bookingSettings($branch->id);
    $member = bookingMember();
    $slot = bookingFutureSlot();

    // An ACTIVE catalog service so item_name is snapshotted (not the raw code).
    $package = App\Models\Package::create([
        'name' => 'Catalog Package', 'price' => '1000.00', 'valid_days' => 30,
        'branch_id' => null, 'is_active' => true,
    ]);
    $package->lines()->create([
        'item_code' => 'MASSAGE_60', 'item_name' => 'Thai Massage 60',
        'item_type' => ItemType::Service, 'qty' => 10,
    ]);

    $booking = app(BookingService::class)->create($branch->id, $member, 'MASSAGE_60', $slot, BookingOrigin::Member);

    expect($booking->status)->toBe(BookingStatus::Confirmed);
    expect($booking->created_via)->toBe(BookingOrigin::Member);
    expect($booking->created_by_user_id)->toBeNull(); // member origin ⇒ no users row
    expect($booking->member_id)->toBe($member->id);
    expect($booking->branch_id)->toBe($branch->id);

    // item_name snapshotted from the active catalog.
    expect($booking->item_name)->toBe('Thai Massage 60');

    // scheduled_end = start + slot_length_minutes (60), and the length snapshot.
    expect($booking->slot_length_minutes)->toBe(60);
    expect($booking->scheduled_start->equalTo($slot))->toBeTrue();
    expect($booking->scheduled_end->equalTo($slot->addMinutes(60)))->toBeTrue();
});

it('falls back to the item code as item_name when the code is not in the active catalog', function () {
    $branch = bookingBranch();
    bookingSettings($branch->id);
    $member = bookingMember();

    // No catalog line for UNKNOWN_SVC → item_name falls back to the code (§3.2).
    $booking = app(BookingService::class)->create($branch->id, $member, 'UNKNOWN_SVC', bookingFutureSlot(), BookingOrigin::Member);

    expect($booking->item_name)->toBe('UNKNOWN_SVC');
});

it('records created_by_user_id for a staff-origin booking', function () {
    $branch = bookingBranch();
    bookingSettings($branch->id);
    $member = bookingMember();
    $staff = bookingStaff();

    $booking = app(BookingService::class)->create(
        $branch->id, $member, 'MASSAGE_60', bookingFutureSlot(), BookingOrigin::Staff, createdBy: $staff,
    );

    expect($booking->created_via)->toBe(BookingOrigin::Staff);
    expect($booking->created_by_user_id)->toBe($staff->id);
});

// ---------------------------------------------------------------------------
// availableSlots — remaining / is_full / past-omission / not-bookable
// ---------------------------------------------------------------------------

it('returns an empty availability grid for a non-bookable branch', function () {
    $branch = bookingBranch();
    bookingSettings($branch->id, overrides: ['is_bookable' => false]);

    expect(app(BookingService::class)->availableSlots($branch->id, bookingNow()))->toBe([]);
});

it('computes remaining per slot and marks a full slot is_full', function () {
    $branch = bookingBranch();
    bookingSettings($branch->id, capacity: 2);
    $tomorrow = bookingNow()->startOfDay()->addDay();
    $slot = $tomorrow->setTime(14, 0, 0);

    // Two confirmed bookings into the 14:00 slot → it is full.
    $m1 = bookingMember(['phone' => '0850000011']);
    $m2 = bookingMember(['phone' => '0850000012']);
    app(BookingService::class)->create($branch->id, $m1, 'MASSAGE_60', $slot, BookingOrigin::Member);
    app(BookingService::class)->create($branch->id, $m2, 'MASSAGE_60', $slot, BookingOrigin::Member);

    $slots = collect(app(BookingService::class)->availableSlots($branch->id, $tomorrow))
        ->keyBy('start');

    $key = $slot->toIso8601String();
    expect($slots->has($key))->toBeTrue();
    expect($slots[$key]['remaining'])->toBe(0);
    expect($slots[$key]['is_full'])->toBeTrue();

    // An untouched 15:00 slot still has full capacity remaining.
    $free = $tomorrow->setTime(15, 0, 0)->toIso8601String();
    expect($slots[$free]['remaining'])->toBe(2);
    expect($slots[$free]['is_full'])->toBeFalse();
});

it('omits past slots for today but keeps future ones', function () {
    $branch = bookingBranch();
    bookingSettings($branch->id); // 09:00–20:00, now is 10:00 today
    $today = bookingNow(); // 2026-08-03 10:00

    $starts = collect(app(BookingService::class)->availableSlots($branch->id, $today))
        ->pluck('start')
        ->map(fn (string $iso): string => CarbonImmutable::parse($iso)->toDateTimeString());

    // 09:00 already passed (before now 10:00) → omitted.
    expect($starts->contains('2026-08-03 09:00:00'))->toBeFalse();
    // A later same-day slot remains.
    expect($starts->contains('2026-08-03 14:00:00'))->toBeTrue();
});

// ---------------------------------------------------------------------------
// checkIn — the MONEY path (redemption + booking_id stamp), and rollback
// ---------------------------------------------------------------------------

it('checks in a confirmed booking: deducts 1, stamps ledger booking_id, completes', function () {
    $branch = bookingBranch();
    bookingSettings($branch->id);
    $member = bookingMember();
    bookingLot($member, 'MASSAGE_60', 5); // redeemable balance

    $staff = bookingStaff(); // no home branch → unscoped-ish; lot is any-branch anyway
    $booking = app(BookingService::class)->create($branch->id, $member, 'MASSAGE_60', bookingFutureSlot(), BookingOrigin::Member);

    $completed = app(BookingService::class)->checkIn($booking, $staff);

    // Booking settled on completed with the audit legs stamped.
    expect($completed->status)->toBe(BookingStatus::Completed);
    expect($completed->checked_in_by_user_id)->toBe($staff->id);
    expect($completed->checked_in_at)->not->toBeNull();
    expect($completed->completed_at)->not->toBeNull();

    // Exactly one unit consumed.
    expect(Entitlement::where('item_code', 'MASSAGE_60')->sole()->qty_remaining)->toBe(4);

    // The redeem ledger row carries THIS booking's id (§7) — booking_id is a FK.
    $redeem = EntitlementLedger::where('reason', LedgerReason::Redeem)->sole();
    expect($redeem->delta)->toBe(-1);
    expect($redeem->booking_id)->toBe($booking->id);
    expect($redeem->staff_id)->toBe($staff->id);

    // The booking->ledgerEntries relation resolves via booking_id.
    expect($booking->fresh()->ledgerEntries()->count())->toBe(1);
});

it('rolls back check-in entirely when the member has insufficient balance', function () {
    $branch = bookingBranch();
    bookingSettings($branch->id);
    $member = bookingMember();
    // NO lot for MASSAGE_60 → redemption will throw RedemptionException.

    $staff = bookingStaff();
    $booking = app(BookingService::class)->create($branch->id, $member, 'MASSAGE_60', bookingFutureSlot(), BookingOrigin::Member);

    expect(fn () => app(BookingService::class)->checkIn($booking, $staff))
        ->toThrow(RedemptionException::class);

    // Whole txn rolled back: booking stays confirmed, no redeem row, nothing stamped.
    $fresh = $booking->fresh();
    expect($fresh->status)->toBe(BookingStatus::Confirmed);
    expect($fresh->checked_in_at)->toBeNull();
    expect($fresh->completed_at)->toBeNull();
    expect(EntitlementLedger::where('reason', LedgerReason::Redeem)->count())->toBe(0);
});

it('leaves the entitlement untouched when check-in redemption fails', function () {
    $branch = bookingBranch();
    bookingSettings($branch->id);
    $member = bookingMember();
    // Only 0 remaining of the booked item (a used-up lot) → insufficient.
    bookingLot($member, 'MASSAGE_60', 0);

    $staff = bookingStaff();
    $booking = app(BookingService::class)->create($branch->id, $member, 'MASSAGE_60', bookingFutureSlot(), BookingOrigin::Member);

    expect(fn () => app(BookingService::class)->checkIn($booking, $staff))
        ->toThrow(RedemptionException::class);

    expect(Entitlement::where('item_code', 'MASSAGE_60')->sole()->qty_remaining)->toBe(0);
    expect($booking->fresh()->status)->toBe(BookingStatus::Confirmed);
});

it('refuses to check in a booking that is not confirmed', function () {
    $branch = bookingBranch();
    bookingSettings($branch->id);
    $member = bookingMember();
    bookingLot($member, 'MASSAGE_60', 5);
    $staff = bookingStaff();

    $booking = app(BookingService::class)->create($branch->id, $member, 'MASSAGE_60', bookingFutureSlot(), BookingOrigin::Member);
    app(BookingService::class)->cancel($booking); // now cancelled (terminal)

    expect(fn () => app(BookingService::class)->checkIn($booking->fresh(), $staff))
        ->toThrow(BookingException::class);

    // No redemption happened on the wrong-state check-in.
    expect(Entitlement::where('item_code', 'MASSAGE_60')->sole()->qty_remaining)->toBe(5);
});

// ---------------------------------------------------------------------------
// cancel / no_show
// ---------------------------------------------------------------------------

it('cancel frees the slot so a re-create into it succeeds', function () {
    $branch = bookingBranch();
    bookingSettings($branch->id, capacity: 1); // single chair
    $slot = bookingFutureSlot();

    $m1 = bookingMember(['phone' => '0850000021']);
    $m2 = bookingMember(['phone' => '0850000022']);

    $first = app(BookingService::class)->create($branch->id, $m1, 'MASSAGE_60', $slot, BookingOrigin::Member);

    // Slot is full — a second create is rejected.
    expect(fn () => app(BookingService::class)->create($branch->id, $m2, 'MASSAGE_60', $slot, BookingOrigin::Member))
        ->toThrow(BookingException::class);

    // Cancel the first → the chair is freed (leaves the capacity-holding set).
    app(BookingService::class)->cancel($first);
    expect($first->fresh()->status)->toBe(BookingStatus::Cancelled);
    expect($first->fresh()->cancelled_at)->not->toBeNull();

    // Now the re-create into the same slot succeeds.
    $second = app(BookingService::class)->create($branch->id, $m2, 'MASSAGE_60', $slot, BookingOrigin::Member);
    expect($second->status)->toBe(BookingStatus::Confirmed);
});

it('records the acting staff on a staff cancel and null on a member self-cancel', function () {
    $branch = bookingBranch();
    bookingSettings($branch->id, capacity: 2);
    $staff = bookingStaff();

    $m1 = bookingMember(['phone' => '0850000031']);
    $m2 = bookingMember(['phone' => '0850000032']);

    $b1 = app(BookingService::class)->create($branch->id, $m1, 'MASSAGE_60', bookingFutureSlot(daysAhead: 1, hour: 14), BookingOrigin::Member);
    $b2 = app(BookingService::class)->create($branch->id, $m2, 'MASSAGE_60', bookingFutureSlot(daysAhead: 1, hour: 15), BookingOrigin::Member);

    app(BookingService::class)->cancel($b1, actor: $staff);
    app(BookingService::class)->cancel($b2, actor: null);

    expect($b1->fresh()->cancelled_by_user_id)->toBe($staff->id);
    expect($b2->fresh()->cancelled_by_user_id)->toBeNull();
});

it('refuses to cancel a booking that is not confirmed', function () {
    $branch = bookingBranch();
    bookingSettings($branch->id);
    $member = bookingMember();

    $booking = app(BookingService::class)->create($branch->id, $member, 'MASSAGE_60', bookingFutureSlot(), BookingOrigin::Member);
    app(BookingService::class)->cancel($booking); // → cancelled

    // A second cancel on the terminal row is a wrong-state transition.
    expect(fn () => app(BookingService::class)->cancel($booking->fresh()))
        ->toThrow(BookingException::class);
});

it('markNoShow flips a confirmed booking to no_show', function () {
    $branch = bookingBranch();
    bookingSettings($branch->id);
    $member = bookingMember();

    $booking = app(BookingService::class)->create($branch->id, $member, 'MASSAGE_60', bookingFutureSlot(), BookingOrigin::Member);

    app(BookingService::class)->markNoShow($booking);

    expect($booking->fresh()->status)->toBe(BookingStatus::NoShow);
});

it('refuses to mark no_show on a booking that is not confirmed', function () {
    $branch = bookingBranch();
    bookingSettings($branch->id);
    $member = bookingMember();

    $booking = app(BookingService::class)->create($branch->id, $member, 'MASSAGE_60', bookingFutureSlot(), BookingOrigin::Member);
    app(BookingService::class)->cancel($booking); // terminal

    expect(fn () => app(BookingService::class)->markNoShow($booking->fresh()))
        ->toThrow(BookingException::class);
});
