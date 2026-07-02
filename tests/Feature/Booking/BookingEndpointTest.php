<?php

declare(strict_types=1);

// Booking HTTP surfaces (docs/phase7-booking-design.md §6–§8), reframed for the credit
// WALLET (check-in DEBITS the wallet via WalletService):
//   MEMBER (routes/member.php, behind `auth:members`):
//     - member.bookings.index/availability/store/cancel — every action is FOR the
//       authenticated member; a member may cancel their OWN booking but NEVER another's.
//     - availability returns JSON `{ slots: [...] }`; a guest is redirected away.
//   ADMIN (routes/admin.php, behind ['auth','verified','role:owner,staff']):
//     - a guest is redirected to login; a members-guard session cannot reach it
//       ([302,403]-tolerant).
//     - staff check-in DEBITS the wallet (credit_ledger row carries booking_id +
//       staff_id) and completes; insufficient balance leaves the booking confirmed with
//       ZERO debit rows. no-show / cancel transitions. Branch scoping is enforced
//       (staff pinned to home branch → 403 on another branch's booking; owner unscoped).
//
// Flash is Inertia::flash('toast', ...); success is asserted via redirect + DB state.
// Wallet credit is seeded ONLY via WalletService::topUp. TIME: travelTo() a fixed
// weekday mid-morning so grid-aligned FUTURE slots are deterministic.

use App\Enums\BookingOrigin;
use App\Enums\BookingStatus;
use App\Enums\CreditLedgerReason;
use App\Enums\CreditSource;
use App\Enums\UserRole;
use App\Models\Booking;
use App\Models\Branch;
use App\Models\BranchBookingSetting;
use App\Models\CreditLedger;
use App\Models\Member;
use App\Models\Service;
use App\Models\User;
use App\Services\Wallet\WalletService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/** Fixed local "now" — Mon 2026-08-03 10:00, mid-window, on the 60-min grid. */
function endpointNow(): CarbonImmutable
{
    return CarbonImmutable::parse('2026-08-03 10:00:00');
}

/** A grid-aligned FUTURE slot relative to endpointNow() (default 14:00 tomorrow). */
function endpointSlot(int $daysAhead = 1, int $hour = 14): CarbonImmutable
{
    return endpointNow()->startOfDay()->addDays($daysAhead)->setTime($hour, 0, 0);
}

function endpointBranch(string $name = 'Endpoint Branch'): Branch
{
    return Branch::create(['name' => $name, 'is_active' => true]);
}

/** @param  array<string, mixed>  $overrides */
function endpointSettings(int $branchId, int $capacity = 2, array $overrides = []): BranchBookingSetting
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

function endpointMember(array $overrides = []): Member
{
    return Member::create(array_merge([
        'name' => 'Endpoint Booker',
        'phone' => '0860000000',
        'is_active' => true,
    ], $overrides));
}

/** Active, verified admin operator; a branchId pins their home branch. */
function endpointUser(UserRole $role, ?int $branchId = null): User
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
function endpointService(string $itemCode = 'MASSAGE_60', string $price = '300.00'): Service
{
    return Service::create([
        'item_code' => $itemCode,
        'name' => 'Thai Massage 60',
        'price' => $price,
        'branch_id' => null,
        'is_active' => true,
    ]);
}

/** Seed spendable wallet credit honestly (via the money authority). */
function endpointCredit(Member $member, string $paid): void
{
    app(WalletService::class)->topUp($member, $paid, '0.00', CreditSource::Topup, null);
}

/**
 * Persist a confirmed booking directly (bypassing the service) so lifecycle endpoints
 * have a live row to act on. created_via/created_by are consistent with the origin
 * (member ⇒ null, staff ⇒ a real user id).
 */
function endpointBooking(
    Member $member,
    int $branchId,
    CarbonImmutable $start,
    string $itemCode = 'MASSAGE_60',
    BookingOrigin $via = BookingOrigin::Member,
    ?int $createdByUserId = null,
): Booking {
    return Booking::create([
        'member_id' => $member->id,
        'branch_id' => $branchId,
        'item_code' => $itemCode,
        'item_name' => $itemCode,
        'scheduled_start' => $start,
        'scheduled_end' => $start->addMinutes(60),
        'slot_length_minutes' => 60,
        'status' => BookingStatus::Confirmed,
        'created_via' => $via,
        'created_by_user_id' => $createdByUserId,
        'note' => null,
    ]);
}

beforeEach(function () {
    $this->travelTo(endpointNow());
});

// ===========================================================================
// MEMBER endpoints (auth:members)
// ===========================================================================

it('redirects a guest away from the member booking index', function () {
    $this->get(route('member.bookings.index'))->assertRedirect();
});

it('redirects a guest away from member availability', function () {
    $branch = endpointBranch();
    endpointSettings($branch->id);

    $this->get(route('member.bookings.availability', ['branch_id' => $branch->id, 'date' => endpointNow()->toDateString()]))
        ->assertRedirect();
});

it('redirects a guest posting to the member store', function () {
    $branch = endpointBranch();
    endpointSettings($branch->id);

    $this->post(route('member.bookings.store'), [
        'branch_id' => $branch->id,
        'item_code' => 'MASSAGE_60',
        'scheduled_start' => endpointSlot()->toIso8601String(),
    ])->assertRedirect();

    $this->assertDatabaseCount('bookings', 0);
});

it('returns JSON slots from member availability for the acting member', function () {
    $branch = endpointBranch();
    endpointSettings($branch->id, capacity: 2);
    $member = endpointMember();

    $this->actingAs($member, 'members')
        ->getJson(route('member.bookings.availability', [
            'branch_id' => $branch->id,
            'date' => endpointNow()->startOfDay()->addDay()->toDateString(),
        ]))
        ->assertOk()
        ->assertJsonStructure(['slots' => [['start', 'end', 'remaining', 'is_full']]]);
});

it('lets a member create a booking for themselves (created_via=member, no creator)', function () {
    $branch = endpointBranch();
    endpointSettings($branch->id);
    $member = endpointMember();

    $this->actingAs($member, 'members')
        ->post(route('member.bookings.store'), [
            'branch_id' => $branch->id,
            'item_code' => 'MASSAGE_60',
            'scheduled_start' => endpointSlot()->toIso8601String(),
        ])
        ->assertRedirect(route('member.bookings.index'));

    $booking = Booking::sole();
    expect($booking->member_id)->toBe($member->id);
    expect($booking->created_via)->toBe(BookingOrigin::Member);
    expect($booking->created_by_user_id)->toBeNull();
    expect($booking->status)->toBe(BookingStatus::Confirmed);
});

it('lets a member cancel their OWN confirmed booking', function () {
    $branch = endpointBranch();
    endpointSettings($branch->id);
    $member = endpointMember();
    $booking = endpointBooking($member, $branch->id, endpointSlot());

    $this->actingAs($member, 'members')
        ->delete(route('member.bookings.cancel', $booking))
        ->assertRedirect(route('member.bookings.index'));

    expect($booking->fresh()->status)->toBe(BookingStatus::Cancelled);
    expect($booking->fresh()->cancelled_by_user_id)->toBeNull();
});

it('does NOT let a member cancel ANOTHER member booking', function () {
    $branch = endpointBranch();
    endpointSettings($branch->id);

    $owner = endpointMember(['phone' => '0860000001']);
    $other = endpointMember(['phone' => '0860000002']);
    $booking = endpointBooking($owner, $branch->id, endpointSlot());

    $response = $this->actingAs($other, 'members')
        ->delete(route('member.bookings.cancel', $booking));

    expect(in_array($response->status(), [302, 403], true))->toBeTrue();
    expect($booking->fresh()->status)->toBe(BookingStatus::Confirmed);
});

// ===========================================================================
// ADMIN endpoints (auth + verified + role:owner,staff)
// ===========================================================================

it('redirects a guest to login from the admin booking index', function () {
    $this->get(route('bookings.index'))->assertRedirect(route('login'));
});

it('does not let a members-guard session reach the admin booking index', function () {
    $member = endpointMember();

    $response = $this->actingAs($member, 'members')->get(route('bookings.index'));

    expect(in_array($response->status(), [302, 403], true))->toBeTrue();
});

it('does not let a members-guard session store an admin booking', function () {
    $branch = endpointBranch();
    endpointSettings($branch->id);
    $member = endpointMember();

    $response = $this->actingAs($member, 'members')->post(route('bookings.store'), [
        'member_id' => $member->id,
        'branch_id' => $branch->id,
        'item_code' => 'MASSAGE_60',
        'scheduled_start' => endpointSlot()->toIso8601String(),
    ]);

    expect(in_array($response->status(), [302, 403], true))->toBeTrue();
    $this->assertDatabaseCount('bookings', 0);
});

it('lets staff book on behalf of a member (created_via=staff, creator recorded)', function () {
    $branch = endpointBranch();
    endpointSettings($branch->id);
    $member = endpointMember();
    $staff = endpointUser(UserRole::Staff, $branch->id);

    $this->actingAs($staff)
        ->post(route('bookings.store'), [
            'member_id' => $member->id,
            'branch_id' => $branch->id,
            'item_code' => 'MASSAGE_60',
            'scheduled_start' => endpointSlot()->toIso8601String(),
        ])
        ->assertRedirect(); // back()

    $booking = Booking::sole();
    expect($booking->created_via)->toBe(BookingOrigin::Staff);
    expect($booking->created_by_user_id)->toBe($staff->id);
    expect($booking->member_id)->toBe($member->id);
});

it('rejects a staff booking for an inactive member with a validation error', function () {
    $branch = endpointBranch();
    endpointSettings($branch->id);
    $member = endpointMember(['is_active' => false]);
    $staff = endpointUser(UserRole::Staff, $branch->id);

    $this->actingAs($staff)
        ->post(route('bookings.store'), [
            'member_id' => $member->id,
            'branch_id' => $branch->id,
            'item_code' => 'MASSAGE_60',
            'scheduled_start' => endpointSlot()->toIso8601String(),
        ])
        ->assertSessionHasErrors(['member_id']);

    $this->assertDatabaseCount('bookings', 0);
});

it('lets staff check in a confirmed booking: wallet debited, ledger carries booking_id, completes', function () {
    $branch = endpointBranch();
    endpointSettings($branch->id);
    $member = endpointMember();
    endpointService('MASSAGE_60', '300.00');
    endpointCredit($member, '1000.00');
    $staff = endpointUser(UserRole::Staff, $branch->id);
    $booking = endpointBooking($member, $branch->id, endpointSlot());

    $this->actingAs($staff)
        ->post(route('bookings.check-in', $booking))
        ->assertRedirect(); // back()

    expect($booking->fresh()->status)->toBe(BookingStatus::Completed);
    expect(app(WalletService::class)->balance($member))->toBe('700.00');

    $debit = CreditLedger::query()->where('reason', CreditLedgerReason::Debit)->sole();
    expect($debit->booking_id)->toBe($booking->id);
    expect($debit->staff_id)->toBe($staff->id);
    expect($debit->delta)->toBe('-300.00');
});

it('surfaces insufficient balance on check-in as an error and leaves the booking confirmed', function () {
    $branch = endpointBranch();
    endpointSettings($branch->id);
    $member = endpointMember();
    endpointService('MASSAGE_60', '300.00');
    // No credit → the wallet debit throws; the controller catches it.
    $staff = endpointUser(UserRole::Staff, $branch->id);
    $booking = endpointBooking($member, $branch->id, endpointSlot());

    $this->actingAs($staff)
        ->post(route('bookings.check-in', $booking))
        ->assertRedirect(); // back() with an error toast

    expect($booking->fresh()->status)->toBe(BookingStatus::Confirmed);
    expect(CreditLedger::query()->where('reason', CreditLedgerReason::Debit)->count())->toBe(0);
});

it('lets staff mark a confirmed booking as no_show', function () {
    $branch = endpointBranch();
    endpointSettings($branch->id);
    $member = endpointMember();
    $staff = endpointUser(UserRole::Staff, $branch->id);
    $booking = endpointBooking($member, $branch->id, endpointSlot());

    $this->actingAs($staff)
        ->post(route('bookings.no-show', $booking))
        ->assertRedirect();

    expect($booking->fresh()->status)->toBe(BookingStatus::NoShow);
});

it('lets staff cancel a confirmed booking and records the acting staff', function () {
    $branch = endpointBranch();
    endpointSettings($branch->id);
    $member = endpointMember();
    $staff = endpointUser(UserRole::Staff, $branch->id);
    $booking = endpointBooking($member, $branch->id, endpointSlot());

    $this->actingAs($staff)
        ->delete(route('bookings.cancel', $booking))
        ->assertRedirect();

    expect($booking->fresh()->status)->toBe(BookingStatus::Cancelled);
    expect($booking->fresh()->cancelled_by_user_id)->toBe($staff->id);
});

it('branch-scopes staff: cannot check in a booking at ANOTHER branch (403)', function () {
    $branchA = endpointBranch('Branch A');
    $branchB = endpointBranch('Branch B');
    endpointSettings($branchA->id);
    endpointSettings($branchB->id);

    $member = endpointMember();
    endpointService('MASSAGE_60', '300.00');
    endpointCredit($member, '1000.00');

    $staffA = endpointUser(UserRole::Staff, $branchA->id);
    $bookingB = endpointBooking($member, $branchB->id, endpointSlot());

    $response = $this->actingAs($staffA)->post(route('bookings.check-in', $bookingB));

    expect(in_array($response->status(), [302, 403], true))->toBeTrue();

    // Nothing debited, booking untouched.
    expect($bookingB->fresh()->status)->toBe(BookingStatus::Confirmed);
    expect(CreditLedger::query()->where('reason', CreditLedgerReason::Debit)->count())->toBe(0);
});

it('lets an owner (unscoped) check in a booking at any branch', function () {
    $branchA = endpointBranch('Branch A');
    $branchB = endpointBranch('Branch B');
    endpointSettings($branchA->id);
    endpointSettings($branchB->id);

    $member = endpointMember();
    endpointService('MASSAGE_60', '300.00');
    endpointCredit($member, '1000.00');

    $owner = endpointUser(UserRole::Owner);
    $bookingB = endpointBooking($member, $branchB->id, endpointSlot());

    $this->actingAs($owner)
        ->post(route('bookings.check-in', $bookingB))
        ->assertRedirect();

    expect($bookingB->fresh()->status)->toBe(BookingStatus::Completed);
    expect(app(WalletService::class)->balance($member))->toBe('700.00');
});
