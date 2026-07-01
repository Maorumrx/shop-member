<?php

declare(strict_types=1);

// Phase 6 — Member dashboard: GET /member/dashboard (member.dashboard) →
// App\Http\Controllers\Member\DashboardController@index, behind `auth:members`.
// Renders the Inertia component Member/Dashboard with props sourced entirely from
// the shared App\Services\Member\MemberEntitlementQuery (the SAME source of truth
// the admin detail page uses, §6.4) — but with `includeStaff: false`, so the
// member feed NEVER leaks who performed a movement.
//
// Contracts under test:
//   - an authenticated member gets 200 and the Member/Dashboard component;
//   - a guest (no members session) is redirected away (matches MemberRouteAccessTest);
//   - DATA ISOLATION: acting as member A, the props reflect ONLY A's lots/balance/
//     history and contain NONE of member B's item codes or lot ids (no cross leak);
//   - the member `history` rows OMIT `staff_name` (member view), even though the
//     underlying ledger row carries a staff_id;
//   - `lots` lists ACTIVE lots only (used_up excluded), each with `is_near_expiry`
//     true for a dated lot inside the 30-day window and false for a far-future one;
//   - `balanceByType` sums qty_remaining per item over ACTIVE, non-expired
//     entitlements — a fully-consumed (0) or expired entitlement is excluded.
//
// Read-path only (no writes through the app), so lots/entitlements/ledger rows are
// minted directly the way RedemptionEndpointTest's `redeemEndpointLot` helper does.
// Inertia component/prop assertions need no JS build (cf. MemberRouteAccessTest).

use App\Enums\EntitlementStatus;
use App\Enums\ItemType;
use App\Enums\LedgerReason;
use App\Enums\UserRole;
use App\Models\Entitlement;
use App\Models\EntitlementLedger;
use App\Models\Member;
use App\Models\MemberPackage;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;

uses(RefreshDatabase::class);

/** A plain active member (the `members` guard identity under test). */
function dashboardMember(array $overrides = []): Member
{
    return Member::create(array_merge([
        'name' => 'Dashboard Member',
        'phone' => '0840000000',
        'is_active' => true,
    ], $overrides));
}

/**
 * Mint a single-line lot for the member so the dashboard has something to render.
 * Mirrors RedemptionEndpointTest::redeemEndpointLot — MemberPackage + Entitlement
 * + an opening purchase ledger row — but with per-line control over the lot/item
 * expiry and status so the read-path projections (near-expiry, active-only,
 * balance exclusions) can be exercised.
 *
 * @return array{lot: MemberPackage, entitlement: Entitlement}
 */
function dashboardLot(
    Member $member,
    string $itemCode,
    int $qtyTotal,
    int $qtyRemaining,
    ?\Carbon\CarbonInterface $expiresAt = null,
    ItemType $itemType = ItemType::Service,
    EntitlementStatus $status = EntitlementStatus::Active,
    ?string $itemName = null,
): array {
    $lot = MemberPackage::create([
        'member_id' => $member->id,
        'package_id' => null,
        'branch_id' => null,
        'purchased_at' => now(),
        'expires_at' => $expiresAt,
        'price_paid' => '0.00',
        'status' => $status,
    ]);

    $ent = Entitlement::create([
        'member_package_id' => $lot->id,
        'member_id' => $member->id,
        'item_code' => $itemCode,
        'item_name' => $itemName ?? $itemCode,
        'item_type' => $itemType,
        'qty_total' => $qtyTotal,
        'qty_remaining' => $qtyRemaining,
        'redeem_group' => null,
        'expires_at' => $expiresAt,
        'status' => $status,
    ]);

    // Opening grant — a purchase row is NOT surfaced by recentHistory (which only
    // shows redeem/expire/refund), it just seeds the ledger like production does.
    $ent->ledgerEntries()->create([
        'member_id' => $member->id,
        'delta' => $qtyTotal,
        'reason' => LedgerReason::Purchase,
        'balance_after' => $qtyTotal,
        'booking_id' => null,
        'staff_id' => null,
        'note' => null,
    ]);

    return ['lot' => $lot, 'entitlement' => $ent];
}

/** A real active staff user — `entitlement_ledger.staff_id` FKs to users.id, so it
 *  must reference an existing row (or be null). Built like the admin role helpers. */
function dashboardStaffUser(): User
{
    return User::factory()->create([
        'role' => UserRole::Staff,
        'is_active' => true,
        'email_verified_at' => now(),
    ]);
}

/**
 * Append a redemption movement to an existing entitlement (a member-visible
 * history row, reason=redeem, delta negative). `$staffId` is a REAL users.id (or
 * null) so the FK holds; passing a genuine id lets the staff-omission contract
 * prove the member view still hides `staff_name` even when the row HAS a staff.
 */
function dashboardRedeem(Entitlement $ent, int $qty, ?int $staffId): EntitlementLedger
{
    $balanceAfter = $ent->qty_remaining - $qty;

    return $ent->ledgerEntries()->create([
        'member_id' => $ent->member_id,
        'delta' => -$qty,
        'reason' => LedgerReason::Redeem,
        'balance_after' => $balanceAfter,
        'booking_id' => null,
        'staff_id' => $staffId,
        'note' => null,
    ]);
}

it('lets an authenticated member view the dashboard (Inertia Member/Dashboard)', function () {
    $member = dashboardMember();

    $this->actingAs($member, 'members')
        ->get(route('member.dashboard'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Member/Dashboard')
            ->where('member.name', 'Dashboard Member'));
});

it('redirects an unauthenticated visitor away from the member dashboard', function () {
    // Matches MemberRouteAccessTest — no invented target, just "redirected away".
    $this->get(route('member.dashboard'))->assertRedirect();
});

it('exposes ONLY the acting member data — no cross-member leak', function () {
    $memberA = dashboardMember(['name' => 'Member A', 'phone' => '0811111111']);
    $memberB = dashboardMember(['name' => 'Member B', 'phone' => '0822222222']);

    // One real staff user for both redemptions — the staff isn't the point here, the
    // insert just needs a valid users.id FK (staff isolation is covered elsewhere).
    $staff = dashboardStaffUser();

    // A: an active lot with a redemption on it.
    ['lot' => $lotA, 'entitlement' => $entA] = dashboardLot($memberA, 'A_ITEM', 10, 7, now()->addDays(60));
    dashboardRedeem($entA, 3, staffId: $staff->id);

    // B: a DIFFERENT active lot + its own redemption — must never surface for A.
    ['lot' => $lotB, 'entitlement' => $entB] = dashboardLot($memberB, 'B_ITEM', 5, 2, now()->addDays(60));
    dashboardRedeem($entB, 3, staffId: $staff->id);

    $this->actingAs($memberA, 'members')
        ->get(route('member.dashboard'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Member/Dashboard')
            // balance: exactly A's one item, none of B's.
            ->has('balanceByType', 1)
            ->where('balanceByType.0.item_code', 'A_ITEM')
            // lots: exactly A's one lot, and it is A's lot id (not B's).
            ->has('lots', 1)
            ->where('lots.0.id', $lotA->id)
            ->where('lots.0.items.0.item_name', 'A_ITEM')
            // history: exactly A's one redeem row, referencing A's item.
            ->has('history', 1)
            ->where('history.0.item_name', 'A_ITEM'));

    // Belt-and-suspenders: B's identifiers appear nowhere in A's serialized props.
    $response = $this->actingAs($memberA, 'members')->get(route('member.dashboard'));
    $json = json_encode($response->viewData('page')['props']);
    expect($json)->not->toContain('B_ITEM');
    expect($json)->not->toContain('"id":'.$lotB->id.',"package_name"');
});

it('omits staff_name from the member history feed (never leaks who redeemed)', function () {
    $member = dashboardMember();
    // A REAL staff user performs the redemption — so the ledger row genuinely HAS a
    // staff_id (the admin feed would surface staff_name); the member view must not.
    $staff = dashboardStaffUser();
    // Mint with 10 remaining, then redeem 4 → the redeem row's balance_after is 6.
    ['entitlement' => $ent] = dashboardLot($member, 'SVC1', 10, 10, now()->addDays(60));
    dashboardRedeem($ent, 4, staffId: $staff->id);

    $this->actingAs($member, 'members')
        ->get(route('member.dashboard'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Member/Dashboard')
            ->has('history', 1)
            ->where('history.0.reason', LedgerReason::Redeem->value)
            ->where('history.0.delta', -4)
            ->where('history.0.balance_after', 6)
            // The member view omits the staff column entirely (includeStaff: false).
            ->missing('history.0.staff_name'));

    // Belt-and-suspenders: the key is absent from the serialized props too.
    $response = $this->actingAs($member, 'members')->get(route('member.dashboard'));
    expect(json_encode($response->viewData('page')['props']))->not->toContain('staff_name');
});

it('lists ACTIVE lots only and flags near-expiry correctly', function () {
    $member = dashboardMember();

    // A dated lot expiring in ~10 days → near-expiry (inside the default 30-day window).
    ['lot' => $soon] = dashboardLot($member, 'SOON', 5, 5, now()->addDays(10));
    // A dated lot expiring far in the future → NOT near-expiry.
    ['lot' => $later] = dashboardLot($member, 'LATER', 5, 5, now()->addDays(120));
    // A used_up lot must be excluded from `lots` (active-only projection).
    ['lot' => $usedUp] = dashboardLot(
        $member,
        'DONE',
        5,
        0,
        now()->addDays(60),
        status: EntitlementStatus::UsedUp,
    );

    $this->actingAs($member, 'members')
        ->get(route('member.dashboard'))
        ->assertOk()
        ->assertInertia(function (AssertableInertia $page) use ($soon, $later, $usedUp) {
            $page->component('Member/Dashboard')
                // Only the two ACTIVE lots — the used_up lot is excluded.
                ->has('lots', 2);

            $lots = collect($page->toArray()['props']['lots'])->keyBy('id');

            expect($lots->has($usedUp->id))->toBeFalse();
            expect($lots[$soon->id]['is_near_expiry'])->toBeTrue();
            expect($lots[$later->id]['is_near_expiry'])->toBeFalse();
        });
});

it('sums balanceByType per item and excludes consumed/expired entitlements', function () {
    $member = dashboardMember();

    // Active item with 4 remaining → surfaces as remaining: 4.
    dashboardLot($member, 'KEEP', 10, 4, now()->addDays(60), itemName: 'Keep Me');
    // A terminal, fully-consumed entitlement (status=used_up, qty_remaining 0) is
    // excluded from balanceByType by the `status = active` filter.
    dashboardLot(
        $member,
        'USEDUP',
        5,
        0,
        now()->addDays(60),
        status: EntitlementStatus::UsedUp,
        itemName: 'Used Up Item',
    );
    // An expired-status entitlement is excluded by the same `status = active` filter.
    dashboardLot(
        $member,
        'EXPIRED',
        5,
        5,
        now()->subDay(),
        status: EntitlementStatus::Expired,
        itemName: 'Expired Item',
    );
    // A still-Active row whose expiry has passed is excluded by the
    // `(expires_at IS NULL OR expires_at > now)` filter.
    dashboardLot($member, 'PASTDATE', 5, 5, now()->subDays(2), itemName: 'Past Dated');

    $this->actingAs($member, 'members')
        ->get(route('member.dashboard'))
        ->assertOk()
        ->assertInertia(function (AssertableInertia $page) {
            $page->component('Member/Dashboard');

            $balance = collect($page->toArray()['props']['balanceByType'])->keyBy('item_code');

            // The live item is present with its exact remaining.
            expect($balance->has('KEEP'))->toBeTrue();
            expect($balance['KEEP']['remaining'])->toBe(4);

            // The expired-status item is excluded (status !== active).
            expect($balance->has('EXPIRED'))->toBeFalse();
            // The active-but-past-expiry item is excluded (expires_at <= now).
            expect($balance->has('PASTDATE'))->toBeFalse();
        });
});
