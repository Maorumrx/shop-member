<?php

declare(strict_types=1);

// Phase 7 — Booking HTTP surfaces (docs/phase7-booking-design.md §6–§8):
//   MEMBER (routes/member.php, behind `auth:members`):
//     - member.bookings.index/availability/store/cancel — every action is FOR the
//       authenticated member ($request->user('members')); a member may cancel their
//       OWN booking but NEVER another member's (own-only 403 guard).
//     - availability returns JSON `{ slots: [...] }`.
//     - a guest (no members session) is redirected away.
//   ADMIN (routes/admin.php, behind ['auth','verified','role:owner,staff']):
//     - a guest is redirected to login; a members-guard session cannot reach it
//       ([302,403]-tolerant — cf. RedemptionEndpointTest's members-guard gotcha).
//     - staff check-in / no-show / cancel work; branch scoping is enforced where
//       the controller enforces it (staff pinned to their home branch → 403 on
//       another branch's booking; an owner is unscoped).
//
// Flash is Inertia::flash('toast', ...); success is asserted via redirect + DB
// state (never JS build). booking_id / staff_id on entitlement_ledger are FKs — we
// only ever use REAL User ids and REAL Booking ids.
//
// TIME: the app aliases Date→CarbonImmutable. We $this->travelTo() a fixed weekday
// mid-morning so grid-aligned FUTURE slots are deterministic.
//
// sqlite caveat: DB-level CHECK constraints (chk_bookings_origin) and true row-lock
// concurrency are MariaDB-only and are NOT exercised here.

use App\Enums\BookingOrigin;
use App\Enums\BookingStatus;
use App\Enums\EntitlementStatus;
use App\Enums\ItemType;
use App\Enums\LedgerReason;
use App\Enums\UserRole;
use App\Models\Booking;
use App\Models\Branch;
use App\Models\BranchBookingSetting;
use App\Models\Entitlement;
use App\Models\EntitlementLedger;
use App\Models\Member;
use App\Models\MemberPackage;
use App\Models\User;
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

/** Mint a redeemable lot so a check-in has balance to consume. */
function endpointLot(Member $member, string $itemCode, int $qty, ?int $branchId = null): MemberPackage
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

/**
 * Persist a confirmed booking directly (bypassing the service) so lifecycle
 * endpoints have a live row to act on. created_via/created_by are consistent with
 * the origin CHECK (member ⇒ null, staff ⇒ a real user id).
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
    // Member self-cancel — no acting user recorded.
    expect($booking->fresh()->cancelled_by_user_id)->toBeNull();
});

it('does NOT let a member cancel ANOTHER member booking', function () {
    $branch = endpointBranch();
    endpointSettings($branch->id);

    $owner = endpointMember(['phone' => '0860000001']);
    $other = endpointMember(['phone' => '0860000002']);
    $booking = endpointBooking($owner, $branch->id, endpointSlot());

    // `other` tries to cancel `owner`'s booking → own-only 403 (302|403 tolerant).
    $response = $this->actingAs($other, 'members')
        ->delete(route('member.bookings.cancel', $booking));

    expect(in_array($response->status(), [302, 403], true))->toBeTrue();

    // The victim's booking is untouched.
    expect($booking->fresh()->status)->toBe(BookingStatus::Confirmed);
});

// ===========================================================================
// ADMIN endpoints (auth + verified + role:owner,staff)
// ===========================================================================

it('redirects a guest to login from the admin booking index', function () {
    $this->get(route('bookings.index'))->assertRedirect(route('login'));
});

it('does not let a members-guard session reach the admin booking index', function () {
    // A members-guard session must NOT reach the admin surface. In tests
    // actingAs($member,'members') also makes `members` the DEFAULT guard, so the
    // admin `auth` sees an authenticated principal and the role gate 403s — a real
    // web session would redirect to login. Either way it's blocked; tolerate 302|403.
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

it('lets staff check in a confirmed booking: redemption runs, ledger carries booking_id, completes', function () {
    $branch = endpointBranch();
    endpointSettings($branch->id);
    $member = endpointMember();
    endpointLot($member, 'MASSAGE_60', 5);
    $staff = endpointUser(UserRole::Staff, $branch->id);
    $booking = endpointBooking($member, $branch->id, endpointSlot());

    $this->actingAs($staff)
        ->post(route('bookings.check-in', $booking))
        ->assertRedirect(); // back()

    expect($booking->fresh()->status)->toBe(BookingStatus::Completed);
    expect(Entitlement::where('item_code', 'MASSAGE_60')->sole()->qty_remaining)->toBe(4);

    $redeem = EntitlementLedger::where('reason', LedgerReason::Redeem)->sole();
    expect($redeem->booking_id)->toBe($booking->id);
    expect($redeem->staff_id)->toBe($staff->id);
});

it('surfaces insufficient balance on check-in as an error and leaves the booking confirmed', function () {
    $branch = endpointBranch();
    endpointSettings($branch->id);
    $member = endpointMember();
    // No lot → redemption throws; the controller catches RedemptionException.
    $staff = endpointUser(UserRole::Staff, $branch->id);
    $booking = endpointBooking($member, $branch->id, endpointSlot());

    $this->actingAs($staff)
        ->post(route('bookings.check-in', $booking))
        ->assertRedirect(); // back() with an error toast

    expect($booking->fresh()->status)->toBe(BookingStatus::Confirmed);
    expect(EntitlementLedger::where('reason', LedgerReason::Redeem)->count())->toBe(0);
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
    endpointLot($member, 'MASSAGE_60', 5);

    // Staff whose home branch is A tries to act on a booking AT branch B.
    $staffA = endpointUser(UserRole::Staff, $branchA->id);
    $bookingB = endpointBooking($member, $branchB->id, endpointSlot());

    $response = $this->actingAs($staffA)->post(route('bookings.check-in', $bookingB));

    expect(in_array($response->status(), [302, 403], true))->toBeTrue();

    // Nothing consumed, booking untouched.
    expect($bookingB->fresh()->status)->toBe(BookingStatus::Confirmed);
    expect(EntitlementLedger::where('reason', LedgerReason::Redeem)->count())->toBe(0);
});

it('lets an owner (unscoped) check in a booking at any branch', function () {
    $branchA = endpointBranch('Branch A');
    $branchB = endpointBranch('Branch B');
    endpointSettings($branchA->id);
    endpointSettings($branchB->id);

    $member = endpointMember();
    endpointLot($member, 'MASSAGE_60', 5);

    // Owner has branch_id null → unscoped → may act on branch B.
    $owner = endpointUser(UserRole::Owner);
    $bookingB = endpointBooking($member, $branchB->id, endpointSlot());

    $this->actingAs($owner)
        ->post(route('bookings.check-in', $bookingB))
        ->assertRedirect();

    expect($bookingB->fresh()->status)->toBe(BookingStatus::Completed);
    expect(Entitlement::where('item_code', 'MASSAGE_60')->sole()->qty_remaining)->toBe(4);
});
