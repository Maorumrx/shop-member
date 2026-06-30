<?php

declare(strict_types=1);

// Phase 1 staged test — copied to tests/Feature/ and run AFTER scaffold via docker/phase1.sh.
// Covers Entitlement::scopeRedeemableAt($branchId) and scopeActive (architecture.md §3.7, §5.5, §6.3).

use App\Enums\EntitlementStatus;
use App\Enums\ItemType;
use App\Models\Branch;
use App\Models\Entitlement;
use App\Models\Member;
use App\Models\MemberPackage;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * Build a member_packages "lot" with the given branch scope and expiry.
 * Null $branchId = any-branch redeemable (§5.5).
 */
function makeLot(Member $member, ?int $branchId, ?\DateTimeInterface $expiresAt = null): MemberPackage
{
    return MemberPackage::create([
        'member_id' => $member->id,
        'package_id' => null,
        'branch_id' => $branchId,
        'purchased_at' => now(),
        'expires_at' => $expiresAt,
        'price_paid' => '1000.00',
        'status' => EntitlementStatus::Active,
    ]);
}

/**
 * Build one entitlement under a lot. Snapshots its expiry from the lot by default.
 *
 * @param  array<string, mixed>  $overrides
 */
function makeEntitlement(MemberPackage $lot, array $overrides = []): Entitlement
{
    return Entitlement::create(array_merge([
        'member_package_id' => $lot->id,
        'member_id' => $lot->member_id,
        'item_code' => 'MASSAGE_60',
        'item_name' => 'Thai Massage 60 min',
        'item_type' => ItemType::Service,
        'qty_total' => 10,
        'qty_remaining' => 10,
        'redeem_group' => null,
        'expires_at' => $lot->expires_at,
        'status' => EntitlementStatus::Active,
    ], $overrides));
}

beforeEach(function () {
    $this->branchA = Branch::create(['name' => 'Branch A', 'is_active' => true]);
    $this->branchB = Branch::create(['name' => 'Branch B', 'is_active' => true]);
    $this->member = Member::create([
        'name' => 'Test Member',
        'phone' => '0800000000',
        'is_active' => true,
    ]);
});

it('includes an active entitlement with qty left and a branch-matching lot', function () {
    $lot = makeLot($this->member, $this->branchA->id, now()->addMonth());
    $ent = makeEntitlement($lot);

    $ids = Entitlement::redeemableAt($this->branchA->id)->pluck('id');

    expect($ids)->toContain($ent->id);
});

it('includes an entitlement whose lot is null-branch (any-branch) at any branch', function () {
    $lot = makeLot($this->member, null, now()->addMonth());
    $ent = makeEntitlement($lot);

    // Redeemable at both branches because the lot scope is null = any-branch (§5.5).
    expect(Entitlement::redeemableAt($this->branchA->id)->pluck('id'))->toContain($ent->id);
    expect(Entitlement::redeemableAt($this->branchB->id)->pluck('id'))->toContain($ent->id);
});

it('includes a never-expiring entitlement (expires_at null)', function () {
    $lot = makeLot($this->member, $this->branchA->id, null);
    $ent = makeEntitlement($lot, ['expires_at' => null]);

    expect(Entitlement::redeemableAt($this->branchA->id)->pluck('id'))->toContain($ent->id);
});

it('includes an entitlement expiring in the future', function () {
    $lot = makeLot($this->member, $this->branchA->id, now()->addDay());
    $ent = makeEntitlement($lot, ['expires_at' => now()->addDay()]);

    expect(Entitlement::redeemableAt($this->branchA->id)->pluck('id'))->toContain($ent->id);
});

it('excludes a lot scoped to a different branch', function () {
    $lot = makeLot($this->member, $this->branchB->id, now()->addMonth());
    $ent = makeEntitlement($lot);

    // Querying branch A must NOT return a lot scoped to branch B (§5.5).
    expect(Entitlement::redeemableAt($this->branchA->id)->pluck('id'))->not->toContain($ent->id);
    // ...but it is redeemable at its own branch B.
    expect(Entitlement::redeemableAt($this->branchB->id)->pluck('id'))->toContain($ent->id);
});

it('excludes an expired-status entitlement', function () {
    $lot = makeLot($this->member, $this->branchA->id, now()->addMonth());
    $ent = makeEntitlement($lot, ['status' => EntitlementStatus::Expired]);

    expect(Entitlement::redeemableAt($this->branchA->id)->pluck('id'))->not->toContain($ent->id);
});

it('excludes a used_up-status entitlement', function () {
    $lot = makeLot($this->member, $this->branchA->id, now()->addMonth());
    $ent = makeEntitlement($lot, ['status' => EntitlementStatus::UsedUp]);

    expect(Entitlement::redeemableAt($this->branchA->id)->pluck('id'))->not->toContain($ent->id);
});

it('excludes an entitlement with qty_remaining = 0', function () {
    $lot = makeLot($this->member, $this->branchA->id, now()->addMonth());
    $ent = makeEntitlement($lot, ['qty_remaining' => 0]);

    expect(Entitlement::redeemableAt($this->branchA->id)->pluck('id'))->not->toContain($ent->id);
});

it('excludes an entitlement whose expires_at is in the past', function () {
    $lot = makeLot($this->member, $this->branchA->id, now()->subDay());
    // Status still 'active' but the date has passed — the date predicate must drop it.
    $ent = makeEntitlement($lot, ['expires_at' => now()->subDay()]);

    expect(Entitlement::redeemableAt($this->branchA->id)->pluck('id'))->not->toContain($ent->id);
});

it('returns only the redeemable rows out of a mixed set at branch A', function () {
    // Redeemable: active, qty>0, future expiry, branch A.
    $good = makeEntitlement(makeLot($this->member, $this->branchA->id, now()->addMonth()));
    // Redeemable: any-branch lot.
    $anyBranch = makeEntitlement(makeLot($this->member, null, now()->addMonth()));
    // Redeemable: never expires.
    $neverExpires = makeEntitlement(makeLot($this->member, $this->branchA->id, null), ['expires_at' => null]);

    // Excluded cases:
    $wrongBranch = makeEntitlement(makeLot($this->member, $this->branchB->id, now()->addMonth()));
    $expiredStatus = makeEntitlement(makeLot($this->member, $this->branchA->id, now()->addMonth()), ['status' => EntitlementStatus::Expired]);
    $usedUp = makeEntitlement(makeLot($this->member, $this->branchA->id, now()->addMonth()), ['status' => EntitlementStatus::UsedUp]);
    $zeroQty = makeEntitlement(makeLot($this->member, $this->branchA->id, now()->addMonth()), ['qty_remaining' => 0]);
    $pastExpiry = makeEntitlement(makeLot($this->member, $this->branchA->id, now()->subDay()), ['expires_at' => now()->subDay()]);

    $ids = Entitlement::redeemableAt($this->branchA->id)->pluck('id')->all();

    expect($ids)->toContain($good->id, $anyBranch->id, $neverExpires->id);
    expect($ids)->not->toContain(
        $wrongBranch->id,
        $expiredStatus->id,
        $usedUp->id,
        $zeroQty->id,
        $pastExpiry->id,
    );
    // Exactly the three redeemable rows.
    expect($ids)->toHaveCount(3);
});

it('scopeActive returns only active-status entitlements', function () {
    $lot = makeLot($this->member, $this->branchA->id, now()->addMonth());
    $active = makeEntitlement($lot);
    $expired = makeEntitlement($lot, ['status' => EntitlementStatus::Expired]);
    $usedUp = makeEntitlement($lot, ['status' => EntitlementStatus::UsedUp]);

    $ids = Entitlement::active()->pluck('id')->all();

    expect($ids)->toContain($active->id);
    expect($ids)->not->toContain($expired->id, $usedUp->id);
});

it('scopeActive ignores qty, expiry and branch (status-only filter)', function () {
    $lot = makeLot($this->member, $this->branchA->id, now()->subYear());
    // Active status but zero qty and past expiry — scopeActive still includes it
    // because it filters status only (the redeemability predicates live in scopeRedeemableAt).
    $ent = makeEntitlement($lot, ['qty_remaining' => 0, 'expires_at' => now()->subYear()]);

    expect(Entitlement::active()->pluck('id'))->toContain($ent->id);
});
