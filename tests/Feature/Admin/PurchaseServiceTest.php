<?php

declare(strict_types=1);

// Phase 4 — PurchaseService (the HEART of the system, architecture.md §3.6–§3.8, §5.1–§5.2).
// Calls the service DIRECTLY (no HTTP) to prove the atomic mint contract:
//   - one member_packages "lot" + one entitlement PER package line + one purchase
//     ledger row PER entitlement, all in a single transaction.
//   - the §6.1 invariant the instant the sale commits, for every entitlement:
//       qty_remaining == qty_total == ledger.balance_after   (and delta == +qty_total).
//   - branch_id is the SNAPSHOT of the PACKAGE's redemption scope (§5.5), not the
//     staff's home branch; null = any-branch.
//   - expires_at = purchased_at + valid_days (date part) or null when valid_days null;
//     purchased_at is ~now (NOT the expiry — guards the CarbonImmutable no-mutation point, §5).
//   - price_paid is the passed decimal(10,2) STRING, stored as-is (never a float, §5.6).
//   - rollback: an inactive package or a no-lines package throws PurchaseException and
//     writes ZERO rows (the whole txn rolls back, §3.4, §5.2).
//
// sqlite date precision: assert expiry by ->toDateString() and the purchased_at
// timestamp via assertEqualsWithDelta (sub-second jitter between now() and the insert).

use App\Enums\EntitlementStatus;
use App\Enums\ItemType;
use App\Enums\LedgerReason;
use App\Enums\UserRole;
use App\Exceptions\PurchaseException;
use App\Models\Branch;
use App\Models\Entitlement;
use App\Models\EntitlementLedger;
use App\Models\Member;
use App\Models\MemberPackage;
use App\Models\Package;
use App\Models\User;
use App\Services\Purchase\PurchaseService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/** Active, verified owner/staff acting as the selling operator (ledger.staff_id). */
function purchaseSvcStaff(UserRole $role = UserRole::Staff): User
{
    return User::factory()->create([
        'role' => $role,
        'is_active' => true,
        'email_verified_at' => now(),
    ]);
}

/** A plain active member to buy a package. */
function purchaseSvcMember(): Member
{
    return Member::create([
        'name' => 'Service Buyer',
        'phone' => '0810000000',
        'is_active' => true,
    ]);
}

/**
 * An active package, by default with NO lines so callers add their own set.
 *
 * @param  array<string, mixed>  $overrides
 */
function purchaseSvcPackage(array $overrides = []): Package
{
    return Package::create(array_merge([
        'name' => 'Service Package',
        'price' => '1290.00',
        'valid_days' => 30,
        'branch_id' => null,
        'is_active' => true,
    ], $overrides));
}

/** Attach the canonical two-line set (service qty 10 + addon qty 3) to a package. */
function purchaseSvcAddTwoLines(Package $package): void
{
    $package->lines()->createMany([
        ['item_code' => 'SVC1', 'item_name' => 'Massage 60', 'item_type' => ItemType::Service, 'qty' => 10],
        ['item_code' => 'ADD1', 'item_name' => 'Hot stone', 'item_type' => ItemType::Addon, 'qty' => 3],
    ]);
}

it('mints one lot, one entitlement per line, and one purchase ledger row per entitlement (the §6.1 invariant)', function () {
    $staff = purchaseSvcStaff();
    $member = purchaseSvcMember();
    $package = purchaseSvcPackage();
    purchaseSvcAddTwoLines($package);

    $lot = app(PurchaseService::class)->purchase($member, $package, '1290.00', $staff);

    // Exactly one lot, two entitlements, two ledger rows for this single sale.
    expect(MemberPackage::count())->toBe(1);
    expect(Entitlement::count())->toBe(2);
    expect(EntitlementLedger::count())->toBe(2);

    // The returned lot links back to the buyer + package.
    expect($lot->member_id)->toBe($member->id);
    expect($lot->package_id)->toBe($package->id);
    expect($lot->status)->toBe(EntitlementStatus::Active);
    // The entitlements relation is loaded on the returned lot.
    expect($lot->relationLoaded('entitlements'))->toBeTrue();
    expect($lot->entitlements)->toHaveCount(2);

    // Per entitlement: invariant qty_remaining == qty_total == line.qty, and its
    // single ledger row carries delta == qty, reason == purchase, balance_after == qty,
    // staff_id == staff.id, booking_id == null.
    foreach (['SVC1' => 10, 'ADD1' => 3] as $code => $qty) {
        $ent = Entitlement::where('item_code', $code)->sole();

        expect($ent->member_package_id)->toBe($lot->id);
        expect($ent->member_id)->toBe($member->id);
        expect($ent->qty_total)->toBe($qty);
        expect($ent->qty_remaining)->toBe($qty);
        expect($ent->status)->toBe(EntitlementStatus::Active);

        $ledger = EntitlementLedger::where('entitlement_id', $ent->id)->sole();
        expect($ledger->delta)->toBe($qty);
        expect($ledger->reason)->toBe(LedgerReason::Purchase);
        expect($ledger->balance_after)->toBe($qty);
        expect($ledger->staff_id)->toBe($staff->id);
        expect($ledger->member_id)->toBe($member->id);
        expect($ledger->booking_id)->toBeNull();
    }
});

it('snapshots the lot branch_id from the PACKAGE scope, not the staff branch', function () {
    // The package is scoped to a branch the staff does NOT belong to.
    $packageBranch = Branch::create(['name' => 'Package Branch', 'is_active' => true]);
    $staffBranch = Branch::create(['name' => 'Staff Branch', 'is_active' => true]);

    $staff = purchaseSvcStaff();
    $staff->forceFill(['branch_id' => $staffBranch->id])->save();

    $member = purchaseSvcMember();
    $package = purchaseSvcPackage(['branch_id' => $packageBranch->id]);
    purchaseSvcAddTwoLines($package);

    $lot = app(PurchaseService::class)->purchase($member, $package, '1290.00', $staff);

    // The lot inherits the PACKAGE's branch (§5.5), independent of the seller's home branch.
    expect($lot->branch_id)->toBe($packageBranch->id);
    expect($lot->branch_id)->not->toBe($staffBranch->id);
});

it('makes the lot any-branch (branch_id null) when the package branch_id is null', function () {
    $staff = purchaseSvcStaff();
    $member = purchaseSvcMember();
    $package = purchaseSvcPackage(['branch_id' => null]);
    purchaseSvcAddTwoLines($package);

    $lot = app(PurchaseService::class)->purchase($member, $package, '1290.00', $staff);

    expect($lot->branch_id)->toBeNull();
});

it('sets lot + entitlement expires_at to purchased_at + valid_days (and purchased_at ~now)', function () {
    $staff = purchaseSvcStaff();
    $member = purchaseSvcMember();
    $package = purchaseSvcPackage(['valid_days' => 30]);
    purchaseSvcAddTwoLines($package);

    $expected = now()->addDays(30);

    $lot = app(PurchaseService::class)->purchase($member, $package, '1290.00', $staff);

    // Date part only — sqlite stores seconds, and addDays() preserves time-of-day.
    expect($lot->expires_at)->not->toBeNull();
    expect($lot->expires_at->toDateString())->toBe($expected->toDateString());

    // purchased_at is ~now (NOT the expiry): CarbonImmutable means addDays() returned
    // a fresh instance and never mutated $purchasedAt in the service.
    expect($lot->purchased_at->toDateString())->toBe(now()->toDateString());
    $this->assertEqualsWithDelta(now()->getTimestamp(), $lot->purchased_at->getTimestamp(), 5);
    expect($lot->purchased_at->lessThan($lot->expires_at))->toBeTrue();

    // Every entitlement snapshots the same expiry as its lot.
    foreach ($lot->entitlements as $ent) {
        expect($ent->expires_at)->not->toBeNull();
        expect($ent->expires_at->toDateString())->toBe($expected->toDateString());
    }
});

it('leaves expires_at null on the lot and entitlements when valid_days is null (never expires)', function () {
    $staff = purchaseSvcStaff();
    $member = purchaseSvcMember();
    $package = purchaseSvcPackage(['valid_days' => null]);
    purchaseSvcAddTwoLines($package);

    $lot = app(PurchaseService::class)->purchase($member, $package, '1290.00', $staff);

    expect($lot->expires_at)->toBeNull();
    foreach ($lot->entitlements as $ent) {
        expect($ent->expires_at)->toBeNull();
    }
    // purchased_at is still set (it's the sale instant, not derived from expiry).
    expect($lot->purchased_at)->not->toBeNull();
});

it('stores price_paid as the passed decimal string, never a float', function () {
    $staff = purchaseSvcStaff();
    $member = purchaseSvcMember();
    $package = purchaseSvcPackage(['price' => '1290.00']);
    purchaseSvcAddTwoLines($package);

    // Pass an override price distinct from the list price; it round-trips exactly.
    $lot = app(PurchaseService::class)->purchase($member, $package, '999.50', $staff);

    // decimal:2 cast → exact 2dp string.
    expect($lot->price_paid)->toBe('999.50');
    $this->assertDatabaseHas('member_packages', [
        'id' => $lot->id,
        'price_paid' => '999.50',
    ]);
});

it('rolls back entirely and throws PurchaseException for an inactive package', function () {
    $staff = purchaseSvcStaff();
    $member = purchaseSvcMember();
    $package = purchaseSvcPackage(['is_active' => false]);
    purchaseSvcAddTwoLines($package); // has lines, but inactive → must abort.

    expect(fn () => app(PurchaseService::class)->purchase($member, $package, '1290.00', $staff))
        ->toThrow(PurchaseException::class);

    // The whole transaction rolled back — no lot/entitlement/ledger row persisted.
    $this->assertDatabaseCount('member_packages', 0);
    $this->assertDatabaseCount('entitlements', 0);
    $this->assertDatabaseCount('entitlement_ledger', 0);
});

it('rolls back entirely and throws PurchaseException for a package with no lines', function () {
    $staff = purchaseSvcStaff();
    $member = purchaseSvcMember();
    // Active but empty — nothing to grant, so the sale must abort before any write.
    $package = purchaseSvcPackage(['is_active' => true]);

    expect(fn () => app(PurchaseService::class)->purchase($member, $package, '1290.00', $staff))
        ->toThrow(PurchaseException::class);

    $this->assertDatabaseCount('member_packages', 0);
    $this->assertDatabaseCount('entitlements', 0);
    $this->assertDatabaseCount('entitlement_ledger', 0);
});
