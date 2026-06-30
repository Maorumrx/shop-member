<?php

declare(strict_types=1);

// Phase 5 — Redeem endpoint: POST members/{member}/redemptions (RedemptionController
// + StoreRedemptionRequest, architecture.md §6.3). Route lives behind
// ['auth','verified','role:owner,staff'] in routes/admin.php.
//
// Contracts under test:
//   - owner AND staff can redeem (302 → members.show + qty deducted); a guest is
//     bounced to login; a members-guard session cannot reach it (no rows changed).
//   - qty omitted → defaults to 1.
//   - insufficient balance → error toast + redirect back, ZERO rows changed.
//   - inactive member → `member` validation error, no change.
//   - soft-deleted member → 404 (route binding).
//   - branch scoping: a branch-staff cannot redeem another branch's lot; an owner can.
//
// Flash is Inertia::flash('toast', ...) — success asserted via redirect + DB state.

use App\Enums\EntitlementStatus;
use App\Enums\ItemType;
use App\Enums\LedgerReason;
use App\Enums\UserRole;
use App\Models\Branch;
use App\Models\Entitlement;
use App\Models\EntitlementLedger;
use App\Models\Member;
use App\Models\MemberPackage;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function redeemEndpointUser(UserRole $role, ?int $branchId = null): User
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

function redeemEndpointMember(array $overrides = []): Member
{
    return Member::create(array_merge([
        'name' => 'Endpoint Redeemer',
        'phone' => '0840000000',
        'is_active' => true,
    ], $overrides));
}

/** Mint a single-line lot for the member so there is something to redeem. */
function redeemEndpointLot(Member $member, string $itemCode, int $qty, ?int $branchId = null): MemberPackage
{
    $lot = MemberPackage::create([
        'member_id' => $member->id,
        'package_id' => null,
        'branch_id' => $branchId,
        'purchased_at' => now(),
        'expires_at' => now()->addDays(30),
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
        'expires_at' => now()->addDays(30),
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

it('lets an owner redeem (redirect to show + qty deducted)', function () {
    $member = redeemEndpointMember();
    redeemEndpointLot($member, 'MASSAGE_60', 10);

    $this->actingAs(redeemEndpointUser(UserRole::Owner))
        ->post(route('members.redemptions.store', $member), ['item_code' => 'MASSAGE_60', 'qty' => 3])
        ->assertRedirect(route('members.show', $member));

    expect(Entitlement::where('item_code', 'MASSAGE_60')->sole()->qty_remaining)->toBe(7);
    $this->assertDatabaseHas('entitlement_ledger', [
        'reason' => LedgerReason::Redeem->value,
        'delta' => -3,
        'balance_after' => 7,
    ]);
});

it('lets a staff user redeem', function () {
    $member = redeemEndpointMember();
    redeemEndpointLot($member, 'MASSAGE_60', 10);

    $this->actingAs(redeemEndpointUser(UserRole::Staff))
        ->post(route('members.redemptions.store', $member), ['item_code' => 'MASSAGE_60', 'qty' => 2])
        ->assertRedirect(route('members.show', $member));

    expect(Entitlement::where('item_code', 'MASSAGE_60')->sole()->qty_remaining)->toBe(8);
});

it('defaults qty to 1 when omitted', function () {
    $member = redeemEndpointMember();
    redeemEndpointLot($member, 'MASSAGE_60', 10);

    $this->actingAs(redeemEndpointUser(UserRole::Owner))
        ->post(route('members.redemptions.store', $member), ['item_code' => 'MASSAGE_60'])
        ->assertRedirect(route('members.show', $member));

    expect(Entitlement::where('item_code', 'MASSAGE_60')->sole()->qty_remaining)->toBe(9);
});

it('redirects a guest to login and changes nothing', function () {
    $member = redeemEndpointMember();
    redeemEndpointLot($member, 'MASSAGE_60', 10);

    $this->post(route('members.redemptions.store', $member), ['item_code' => 'MASSAGE_60'])
        ->assertRedirect(route('login'));

    expect(Entitlement::where('item_code', 'MASSAGE_60')->sole()->qty_remaining)->toBe(10);
});

it('does not let a members-guard session reach the redeem route', function () {
    $member = redeemEndpointMember();
    redeemEndpointLot($member, 'MASSAGE_60', 10);

    // A members-guard session must NOT perform an admin redemption. Note: in tests
    // `actingAs($member, 'members')` also makes `members` the DEFAULT guard, so the
    // admin `auth` middleware sees it as authenticated and the role gate 403s —
    // whereas a real LINE session (default guard `web`) would redirect to login.
    // Either way the request is blocked and nothing changes; tolerate 302|403.
    $response = $this->actingAs($member, 'members')
        ->post(route('members.redemptions.store', $member), ['item_code' => 'MASSAGE_60']);

    expect(in_array($response->status(), [302, 403], true))->toBeTrue();

    expect(Entitlement::where('item_code', 'MASSAGE_60')->sole()->qty_remaining)->toBe(10);
    expect(EntitlementLedger::where('reason', LedgerReason::Redeem)->count())->toBe(0);
});

it('flashes an error and changes nothing when the balance is insufficient', function () {
    $member = redeemEndpointMember();
    redeemEndpointLot($member, 'MASSAGE_60', 2);

    $this->actingAs(redeemEndpointUser(UserRole::Owner))
        ->post(route('members.redemptions.store', $member), ['item_code' => 'MASSAGE_60', 'qty' => 5])
        ->assertRedirect(); // back()

    // No deduction, no redeem ledger row (txn rolled back).
    expect(Entitlement::where('item_code', 'MASSAGE_60')->sole()->qty_remaining)->toBe(2);
    expect(EntitlementLedger::where('reason', LedgerReason::Redeem)->count())->toBe(0);
});

it('rejects redeeming for an inactive member with a member validation error', function () {
    $member = redeemEndpointMember(['is_active' => false]);
    redeemEndpointLot($member, 'MASSAGE_60', 10);

    $this->actingAs(redeemEndpointUser(UserRole::Owner))
        ->post(route('members.redemptions.store', $member), ['item_code' => 'MASSAGE_60'])
        ->assertSessionHasErrors(['member']);

    expect(Entitlement::where('item_code', 'MASSAGE_60')->sole()->qty_remaining)->toBe(10);
});

it('returns 404 when redeeming for a soft-deleted member', function () {
    $member = redeemEndpointMember();
    redeemEndpointLot($member, 'MASSAGE_60', 10);
    $member->delete();

    $this->actingAs(redeemEndpointUser(UserRole::Owner))
        ->post(route('members.redemptions.store', $member), ['item_code' => 'MASSAGE_60'])
        ->assertNotFound();
});

it('rejects qty below 1 with a validation error', function () {
    $member = redeemEndpointMember();
    redeemEndpointLot($member, 'MASSAGE_60', 10);

    $this->actingAs(redeemEndpointUser(UserRole::Owner))
        ->post(route('members.redemptions.store', $member), ['item_code' => 'MASSAGE_60', 'qty' => 0])
        ->assertSessionHasErrors(['qty']);

    expect(Entitlement::where('item_code', 'MASSAGE_60')->sole()->qty_remaining)->toBe(10);
});

it('scopes a branch-staff to their branch (cannot redeem another branch lot)', function () {
    $branchA = Branch::create(['name' => 'Branch A', 'is_active' => true]);
    $branchB = Branch::create(['name' => 'Branch B', 'is_active' => true]);

    $member = redeemEndpointMember();
    redeemEndpointLot($member, 'MASSAGE_60', 5, branchId: $branchB->id);

    // Staff A's home branch is A → the branch-B lot is ineligible → insufficient.
    $this->actingAs(redeemEndpointUser(UserRole::Staff, $branchA->id))
        ->post(route('members.redemptions.store', $member), ['item_code' => 'MASSAGE_60', 'qty' => 1])
        ->assertRedirect(); // error toast + back

    expect(Entitlement::where('item_code', 'MASSAGE_60')->sole()->qty_remaining)->toBe(5);
    expect(EntitlementLedger::where('reason', LedgerReason::Redeem)->count())->toBe(0);
});

it('lets an owner redeem a branch-scoped lot (owner unscoped)', function () {
    $branchB = Branch::create(['name' => 'Branch B', 'is_active' => true]);

    $member = redeemEndpointMember();
    redeemEndpointLot($member, 'MASSAGE_60', 5, branchId: $branchB->id);

    // Owner has branch_id null → unscoped → may redeem the branch-B lot.
    $this->actingAs(redeemEndpointUser(UserRole::Owner))
        ->post(route('members.redemptions.store', $member), ['item_code' => 'MASSAGE_60', 'qty' => 4])
        ->assertRedirect(route('members.show', $member));

    expect(Entitlement::where('item_code', 'MASSAGE_60')->sole()->qty_remaining)->toBe(1);
});
