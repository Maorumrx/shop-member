<?php

declare(strict_types=1);

// BookingService (the จองคิว scheduling core, docs/phase7-booking-design.md §5–§8),
// reframed for the credit WALLET: check-in now DEBITS the wallet via
// WalletService::chargeService (the money-wallet reframe of the dropped
// RedemptionService). Calls the service DIRECTLY (no HTTP) to prove:
//   - CAPACITY: confirmed+checked_in hold a chair; a full slot rejects the next create
//     (BookingException) and writes NO row.
//   - VALIDATION: branch not bookable / slot in past / off open-hours / off-grid /
//     beyond max_advance_days / inactive member / same-member same-slot duplicate.
//   - HAPPY PATH: item_name snapshot from the ACTIVE services catalog, derived end,
//     origin/created_by_user_id (member ⇒ null, staff ⇒ user id).
//   - availableSlots: remaining = capacity − (confirmed+checked_in), is_full, past-today
//     omitted, empty when not bookable.
//   - checkIn: the MONEY path — the wallet is debited at the service price, ONE
//     credit_ledger row carries this booking_id, the booking settles on `completed`;
//     an insufficient balance (or unpriced service) rolls the WHOLE txn back (booking
//     stays confirmed, ZERO debit rows, wallet untouched).
//   - cancel / no_show transitions.
//
// TIME: the app aliases Date→CarbonImmutable; we travelTo() a fixed weekday mid-morning
// on the 60-min grid so slot math is deterministic. Wallet credit is seeded ONLY via
// WalletService::topUp so balances are honest. Money is decimal(10,2) STRINGS (§5.6).
//
// sqlite caveat: DB CHECK constraints and TRUE row-lock concurrency are MariaDB-only
// and are NOT exercised here; the app-level guards ARE.

use App\Enums\BookingOrigin;
use App\Enums\BookingStatus;
use App\Enums\CreditLedgerReason;
use App\Enums\CreditSource;
use App\Enums\UserRole;
use App\Exceptions\InsufficientCreditException;
use App\Exceptions\WalletException;
use App\Models\Booking;
use App\Models\Branch;
use App\Models\BranchBookingSetting;
use App\Models\CreditLedger;
use App\Models\Member;
use App\Models\Service;
use App\Models\User;
use App\Services\Booking\BookingException;
use App\Services\Booking\BookingService;
use App\Services\Wallet\WalletService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/** A fixed local reference instant: Mon 2026-08-03 10:00, mid-window, on the 60-min grid. */
function bookingNow(): CarbonImmutable
{
    return CarbonImmutable::parse('2026-08-03 10:00:00');
}

/** A branch to host slots. */
function bookingBranch(string $name = 'Bookable Branch'): Branch
{
    return Branch::create(['name' => $name, 'is_active' => true]);
}

/**
 * Mint a bookable BranchBookingSetting (60-min slots, 09:00–20:00, 30-day advance).
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

/** A grid-aligned FUTURE slot start, relative to bookingNow() (default 14:00 tomorrow). */
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

/** An active priced service the check-in debit resolves. */
function bookingService(string $itemCode = 'MASSAGE_60', string $price = '300.00', string $name = 'Thai Massage 60'): Service
{
    return Service::create([
        'item_code' => $itemCode,
        'name' => $name,
        'price' => $price,
        'branch_id' => null,
        'is_active' => true,
    ]);
}

/** Seed spendable wallet credit for the member honestly (via the money authority). */
function bookingCredit(Member $member, string $paid): void
{
    app(WalletService::class)->topUp($member, $paid, '0.00', CreditSource::Topup, null);
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

    $offGrid = bookingFutureSlot(daysAhead: 1, hour: 14)->addMinutes(30);

    expect(fn () => app(BookingService::class)->create($branch->id, $member, 'MASSAGE_60', $offGrid, BookingOrigin::Member))
        ->toThrow(BookingException::class);

    $this->assertDatabaseCount('bookings', 0);
});

it('rejects a slot beyond the max_advance_days horizon', function () {
    $branch = bookingBranch();
    bookingSettings($branch->id, overrides: ['max_advance_days' => 7]);
    $member = bookingMember();

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

    expect(fn () => app(BookingService::class)->create($branch->id, $member, 'MASSAGE_60', $slot, BookingOrigin::Member))
        ->toThrow(BookingException::class);

    $this->assertDatabaseCount('bookings', 1);
});

// ---------------------------------------------------------------------------
// HAPPY PATH — snapshot + derived end + origin
// ---------------------------------------------------------------------------

it('creates a confirmed member booking: snapshots item_name from the services catalog, derives end, created_by null', function () {
    $branch = bookingBranch();
    bookingSettings($branch->id);
    $member = bookingMember();
    $slot = bookingFutureSlot();

    // An ACTIVE service so item_name is snapshotted (not the raw code).
    bookingService('MASSAGE_60', '300.00', 'Thai Massage 60');

    $booking = app(BookingService::class)->create($branch->id, $member, 'MASSAGE_60', $slot, BookingOrigin::Member);

    expect($booking->status)->toBe(BookingStatus::Confirmed);
    expect($booking->created_via)->toBe(BookingOrigin::Member);
    expect($booking->created_by_user_id)->toBeNull(); // member origin ⇒ no users row
    expect($booking->member_id)->toBe($member->id);
    expect($booking->branch_id)->toBe($branch->id);

    expect($booking->item_name)->toBe('Thai Massage 60');

    expect($booking->slot_length_minutes)->toBe(60);
    expect($booking->scheduled_start->equalTo($slot))->toBeTrue();
    expect($booking->scheduled_end->equalTo($slot->addMinutes(60)))->toBeTrue();
});

it('falls back to the item code as item_name when the code is not an active service', function () {
    $branch = bookingBranch();
    bookingSettings($branch->id);
    $member = bookingMember();

    // No active service for UNKNOWN_SVC → item_name falls back to the code (§3.2).
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

    $m1 = bookingMember(['phone' => '0850000011']);
    $m2 = bookingMember(['phone' => '0850000012']);
    app(BookingService::class)->create($branch->id, $m1, 'MASSAGE_60', $slot, BookingOrigin::Member);
    app(BookingService::class)->create($branch->id, $m2, 'MASSAGE_60', $slot, BookingOrigin::Member);

    $slots = collect(app(BookingService::class)->availableSlots($branch->id, $tomorrow))->keyBy('start');

    $key = $slot->toIso8601String();
    expect($slots->has($key))->toBeTrue();
    expect($slots[$key]['remaining'])->toBe(0);
    expect($slots[$key]['is_full'])->toBeTrue();

    $free = $tomorrow->setTime(15, 0, 0)->toIso8601String();
    expect($slots[$free]['remaining'])->toBe(2);
    expect($slots[$free]['is_full'])->toBeFalse();
});

it('omits past slots for today but keeps future ones', function () {
    $branch = bookingBranch();
    bookingSettings($branch->id); // 09:00–20:00, now is 10:00 today
    $today = bookingNow();

    $starts = collect(app(BookingService::class)->availableSlots($branch->id, $today))
        ->pluck('start')
        ->map(fn (string $iso): string => CarbonImmutable::parse($iso)->toDateTimeString());

    expect($starts->contains('2026-08-03 09:00:00'))->toBeFalse();
    expect($starts->contains('2026-08-03 14:00:00'))->toBeTrue();
});

// ---------------------------------------------------------------------------
// checkIn — the MONEY path (wallet debit + booking_id stamp), and rollback
// ---------------------------------------------------------------------------

it('checks in a confirmed booking: debits the service price, stamps ledger booking_id, completes', function () {
    $branch = bookingBranch();
    bookingSettings($branch->id);
    $member = bookingMember();
    bookingService('MASSAGE_60', '300.00');
    bookingCredit($member, '1000.00'); // spendable balance

    $staff = bookingStaff();
    $booking = app(BookingService::class)->create($branch->id, $member, 'MASSAGE_60', bookingFutureSlot(), BookingOrigin::Member);

    $completed = app(BookingService::class)->checkIn($booking, $staff);

    // Booking settled on completed with the audit legs stamped.
    expect($completed->status)->toBe(BookingStatus::Completed);
    expect($completed->checked_in_by_user_id)->toBe($staff->id);
    expect($completed->checked_in_at)->not->toBeNull();
    expect($completed->completed_at)->not->toBeNull();

    // 300 debited from the 1000 balance.
    expect(app(WalletService::class)->balance($member))->toBe('700.00');

    // The debit ledger row carries THIS booking's id (§7).
    $debit = CreditLedger::query()->where('reason', CreditLedgerReason::Debit)->sole();
    expect($debit->delta)->toBe('-300.00');
    expect($debit->booking_id)->toBe($booking->id);
    expect($debit->staff_id)->toBe($staff->id);

    // The booking->ledgerEntries relation resolves via booking_id.
    expect($booking->fresh()->ledgerEntries()->count())->toBe(1);
});

it('rolls back check-in entirely when the member has insufficient balance', function () {
    $branch = bookingBranch();
    bookingSettings($branch->id);
    $member = bookingMember();
    bookingService('MASSAGE_60', '300.00');
    // NO credit → the wallet debit throws InsufficientCreditException.

    $staff = bookingStaff();
    $booking = app(BookingService::class)->create($branch->id, $member, 'MASSAGE_60', bookingFutureSlot(), BookingOrigin::Member);

    expect(fn () => app(BookingService::class)->checkIn($booking, $staff))
        ->toThrow(InsufficientCreditException::class);

    // Whole txn rolled back: booking stays confirmed, no debit row, nothing stamped.
    $fresh = $booking->fresh();
    expect($fresh->status)->toBe(BookingStatus::Confirmed);
    expect($fresh->checked_in_at)->toBeNull();
    expect($fresh->completed_at)->toBeNull();
    expect(CreditLedger::query()->where('reason', CreditLedgerReason::Debit)->count())->toBe(0);
});

it('leaves the wallet untouched when check-in cannot be fully paid', function () {
    $branch = bookingBranch();
    bookingSettings($branch->id);
    $member = bookingMember();
    bookingService('MASSAGE_60', '300.00');
    bookingCredit($member, '100.00'); // below the price → insufficient

    $staff = bookingStaff();
    $booking = app(BookingService::class)->create($branch->id, $member, 'MASSAGE_60', bookingFutureSlot(), BookingOrigin::Member);

    expect(fn () => app(BookingService::class)->checkIn($booking, $staff))
        ->toThrow(InsufficientCreditException::class);

    expect(app(WalletService::class)->balance($member))->toBe('100.00');
    expect($booking->fresh()->status)->toBe(BookingStatus::Confirmed);
});

it('rolls back check-in when the booked service is not priced (WalletException)', function () {
    $branch = bookingBranch();
    bookingSettings($branch->id);
    $member = bookingMember();
    bookingCredit($member, '1000.00');
    // No service row for the booked code → chargeService throws WalletException.

    $staff = bookingStaff();
    $booking = app(BookingService::class)->create($branch->id, $member, 'MASSAGE_60', bookingFutureSlot(), BookingOrigin::Member);

    expect(fn () => app(BookingService::class)->checkIn($booking, $staff))
        ->toThrow(WalletException::class);

    expect($booking->fresh()->status)->toBe(BookingStatus::Confirmed);
    expect(app(WalletService::class)->balance($member))->toBe('1000.00');
    expect(CreditLedger::query()->where('reason', CreditLedgerReason::Debit)->count())->toBe(0);
});

it('refuses to check in a booking that is not confirmed', function () {
    $branch = bookingBranch();
    bookingSettings($branch->id);
    $member = bookingMember();
    bookingService('MASSAGE_60', '300.00');
    bookingCredit($member, '1000.00');
    $staff = bookingStaff();

    $booking = app(BookingService::class)->create($branch->id, $member, 'MASSAGE_60', bookingFutureSlot(), BookingOrigin::Member);
    app(BookingService::class)->cancel($booking); // now cancelled (terminal)

    expect(fn () => app(BookingService::class)->checkIn($booking->fresh(), $staff))
        ->toThrow(BookingException::class);

    // No debit happened on the wrong-state check-in.
    expect(app(WalletService::class)->balance($member))->toBe('1000.00');
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

    expect(fn () => app(BookingService::class)->create($branch->id, $m2, 'MASSAGE_60', $slot, BookingOrigin::Member))
        ->toThrow(BookingException::class);

    app(BookingService::class)->cancel($first);
    expect($first->fresh()->status)->toBe(BookingStatus::Cancelled);
    expect($first->fresh()->cancelled_at)->not->toBeNull();

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
