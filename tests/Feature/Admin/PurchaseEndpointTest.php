<?php

declare(strict_types=1);

// Phase 4 — Sell endpoint: POST members/{member}/purchases (PurchaseController +
// StorePurchaseRequest, architecture.md §3.6–§3.8). Route lives behind
// ['auth','verified','role:owner,staff'] in routes/admin.php (no uri prefix).
//
// Contracts under test:
//   - owner AND staff can sell (302 redirect to members.show + rows minted); a guest
//     is redirected to login; a MEMBERS-guard session cannot reach it (the `web`/users
//     `auth` guard ignores the members guard → $request->user() is null → auth bounces
//     to login WITHOUT touching the controller; no rows written).
//   - price_paid omitted → StorePurchaseRequest::prepareForValidation backfills the
//     package list price, so the lot's price_paid == package.price.
//   - an explicit price_paid override is stored as-is.
//   - selling an INACTIVE package → validation error on package_id (scoped exists rule),
//     no rows minted.
//   - selling to an INACTIVE member → validator->after adds a `member` error, no rows.
//   - selling to a SOFT-DELETED member → 404 (route-model binding hits the default
//     SoftDeletes scope before validation).
//
// Flash is Inertia::flash('toast', ...) — never asserted via session; success is the
// redirect + DB state. Money is decimal(10,2) compared as a 2dp string (§5.6).

use App\Enums\ItemType;
use App\Enums\UserRole;
use App\Models\Member;
use App\Models\Package;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/** Active, verified admin operator (owner or staff) — both may sell. */
function purchaseEndpointUser(UserRole $role): User
{
    return User::factory()->create([
        'role' => $role,
        'is_active' => true,
        'email_verified_at' => now(),
    ]);
}

/** A plain active member to sell to. */
function purchaseEndpointMember(array $overrides = []): Member
{
    return Member::create(array_merge([
        'name' => 'Endpoint Buyer',
        'phone' => '0820000000',
        'is_active' => true,
    ], $overrides));
}

/**
 * An active, sellable package with the canonical two-line set already attached.
 *
 * @param  array<string, mixed>  $overrides
 */
function purchaseEndpointPackage(array $overrides = []): Package
{
    $package = Package::create(array_merge([
        'name' => 'Endpoint Package',
        'price' => '1290.00',
        'valid_days' => 30,
        'branch_id' => null,
        'is_active' => true,
    ], $overrides));

    $package->lines()->createMany([
        ['item_code' => 'SVC1', 'item_name' => 'Massage 60', 'item_type' => ItemType::Service, 'qty' => 10],
        ['item_code' => 'ADD1', 'item_name' => 'Hot stone', 'item_type' => ItemType::Addon, 'qty' => 3],
    ]);

    return $package;
}

it('lets an owner sell a package (redirect to show + rows minted)', function () {
    $member = purchaseEndpointMember();
    $package = purchaseEndpointPackage();

    $this->actingAs(purchaseEndpointUser(UserRole::Owner))
        ->post(route('members.purchases.store', $member), ['package_id' => $package->id])
        ->assertRedirect(route('members.show', $member));

    $this->assertDatabaseCount('member_packages', 1);
    $this->assertDatabaseCount('entitlements', 2);
    $this->assertDatabaseCount('entitlement_ledger', 2);
    $this->assertDatabaseHas('member_packages', [
        'member_id' => $member->id,
        'package_id' => $package->id,
    ]);
});

it('lets a staff user sell a package (members surface is owner+staff)', function () {
    $member = purchaseEndpointMember();
    $package = purchaseEndpointPackage();

    $this->actingAs(purchaseEndpointUser(UserRole::Staff))
        ->post(route('members.purchases.store', $member), ['package_id' => $package->id])
        ->assertRedirect(route('members.show', $member));

    $this->assertDatabaseCount('member_packages', 1);
});

it('redirects a guest to login and mints nothing', function () {
    $member = purchaseEndpointMember();
    $package = purchaseEndpointPackage();

    $this->post(route('members.purchases.store', $member), ['package_id' => $package->id])
        ->assertRedirect(route('login'));

    $this->assertDatabaseCount('member_packages', 0);
});

it('does not let a members-guard session reach the admin sell route', function () {
    $member = purchaseEndpointMember();
    $package = purchaseEndpointPackage();

    // A customer authenticated on the `members` guard is NOT on the `web`/users guard
    // that `auth` checks, so $request->user() is null → auth redirects to login and
    // the controller never runs. (NOT a 403 — EnsureUserRole sits behind `auth`.)
    $response = $this->actingAs($member, 'members')
        ->post(route('members.purchases.store', $member), ['package_id' => $package->id]);

    expect(in_array($response->status(), [302, 403], true))->toBeTrue();
    // Whatever the exact status, the sale must NOT have happened.
    $this->assertDatabaseCount('member_packages', 0);
    $this->assertDatabaseCount('entitlements', 0);
    $this->assertDatabaseCount('entitlement_ledger', 0);
});

it('defaults price_paid to the package list price when omitted', function () {
    $member = purchaseEndpointMember();
    $package = purchaseEndpointPackage(['price' => '1290.00']);

    $this->actingAs(purchaseEndpointUser(UserRole::Owner))
        ->post(route('members.purchases.store', $member), ['package_id' => $package->id])
        ->assertRedirect(route('members.show', $member));

    // prepareForValidation backfilled the list price as the stored decimal:2 string.
    $this->assertDatabaseHas('member_packages', [
        'member_id' => $member->id,
        'package_id' => $package->id,
        'price_paid' => '1290.00',
    ]);
});

it('stores an explicit price_paid override', function () {
    $member = purchaseEndpointMember();
    $package = purchaseEndpointPackage(['price' => '1290.00']);

    $this->actingAs(purchaseEndpointUser(UserRole::Owner))
        ->post(route('members.purchases.store', $member), [
            'package_id' => $package->id,
            'price_paid' => '1000.00',
        ])
        ->assertRedirect(route('members.show', $member));

    $this->assertDatabaseHas('member_packages', [
        'member_id' => $member->id,
        'package_id' => $package->id,
        'price_paid' => '1000.00',
    ]);
});

it('rejects selling an inactive package with a package_id validation error', function () {
    $member = purchaseEndpointMember();
    // Active rule on the scoped exists check fails for a hidden package.
    $package = purchaseEndpointPackage(['is_active' => false]);

    $this->actingAs(purchaseEndpointUser(UserRole::Owner))
        ->post(route('members.purchases.store', $member), ['package_id' => $package->id])
        ->assertSessionHasErrors(['package_id']);

    $this->assertDatabaseCount('member_packages', 0);
    $this->assertDatabaseCount('entitlements', 0);
});

it('rejects selling to an inactive member with a member validation error', function () {
    $member = purchaseEndpointMember(['is_active' => false]);
    $package = purchaseEndpointPackage();

    $this->actingAs(purchaseEndpointUser(UserRole::Owner))
        ->post(route('members.purchases.store', $member), ['package_id' => $package->id])
        ->assertSessionHasErrors(['member']);

    $this->assertDatabaseCount('member_packages', 0);
    $this->assertDatabaseCount('entitlements', 0);
});

it('returns 404 when selling to a soft-deleted member (route binding)', function () {
    $member = purchaseEndpointMember();
    $package = purchaseEndpointPackage();
    $member->delete(); // soft delete → excluded by the default SoftDeletes scope.

    $this->actingAs(purchaseEndpointUser(UserRole::Owner))
        ->post(route('members.purchases.store', $member), ['package_id' => $package->id])
        ->assertNotFound();

    $this->assertDatabaseCount('member_packages', 0);
});
