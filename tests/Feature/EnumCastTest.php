<?php

declare(strict_types=1);

// Phase 1 staged test — copied to tests/Feature/ and run AFTER scaffold via docker/phase1.sh.
// Covers enum casts (architecture.md §3.7, §3.8, §5.7): Entitlement::status →
// EntitlementStatus, MemberPackage::status → EntitlementStatus, Entitlement::item_type
// → ItemType, EntitlementLedger::reason → LedgerReason — all round-trip through the DB.

use App\Enums\EntitlementStatus;
use App\Enums\ItemType;
use App\Enums\LedgerReason;
use App\Models\Branch;
use App\Models\Entitlement;
use App\Models\EntitlementLedger;
use App\Models\Member;
use App\Models\MemberPackage;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->branch = Branch::create(['name' => 'Enum Branch', 'is_active' => true]);
    $this->member = Member::create(['name' => 'Enum Member', 'is_active' => true]);
    $this->lot = MemberPackage::create([
        'member_id' => $this->member->id,
        'branch_id' => $this->branch->id,
        'purchased_at' => now(),
        'expires_at' => now()->addMonth(),
        'price_paid' => '750.00',
        'status' => EntitlementStatus::Active,
    ]);
});

/**
 * @param  array<string, mixed>  $overrides
 */
function makeEnumEntitlement(MemberPackage $lot, array $overrides = []): Entitlement
{
    return Entitlement::create(array_merge([
        'member_package_id' => $lot->id,
        'member_id' => $lot->member_id,
        'item_code' => 'HOT_STONE',
        'item_name' => 'Hot Stone Add-on',
        'item_type' => ItemType::Addon,
        'qty_total' => 5,
        'qty_remaining' => 5,
        'expires_at' => $lot->expires_at,
        'status' => EntitlementStatus::Active,
    ], $overrides));
}

it('casts Entitlement::status to an EntitlementStatus instance after reload', function () {
    $ent = makeEnumEntitlement($this->lot, ['status' => EntitlementStatus::Active]);

    $fresh = Entitlement::query()->findOrFail($ent->id);

    expect($fresh->status)->toBeInstanceOf(EntitlementStatus::class);
    expect($fresh->status)->toBe(EntitlementStatus::Active);
});

it('round-trips each EntitlementStatus case on Entitlement', function () {
    foreach (EntitlementStatus::cases() as $case) {
        $ent = makeEnumEntitlement($this->lot, ['status' => $case]);
        $fresh = Entitlement::query()->findOrFail($ent->id);

        expect($fresh->status)->toBe($case);
        // Underlying stored value matches the backing string.
        expect($fresh->status->value)->toBe($case->value);
    }
});

it('casts Entitlement::item_type to an ItemType instance after reload', function () {
    $service = makeEnumEntitlement($this->lot, ['item_code' => 'MASSAGE_60', 'item_type' => ItemType::Service]);
    $addon = makeEnumEntitlement($this->lot, ['item_code' => 'HOT_STONE', 'item_type' => ItemType::Addon]);

    expect(Entitlement::query()->findOrFail($service->id)->item_type)->toBe(ItemType::Service);
    expect(Entitlement::query()->findOrFail($addon->id)->item_type)->toBe(ItemType::Addon);
});

it('casts MemberPackage::status to an EntitlementStatus instance (shared vocab §5.7)', function () {
    $lot = MemberPackage::create([
        'member_id' => $this->member->id,
        'branch_id' => $this->branch->id,
        'purchased_at' => now(),
        'expires_at' => now()->addMonth(),
        'price_paid' => '999.00',
        'status' => EntitlementStatus::UsedUp,
    ]);

    $fresh = MemberPackage::query()->findOrFail($lot->id);

    expect($fresh->status)->toBeInstanceOf(EntitlementStatus::class);
    expect($fresh->status)->toBe(EntitlementStatus::UsedUp);
});

it('casts EntitlementLedger::reason to a LedgerReason instance after reload', function () {
    $ent = makeEnumEntitlement($this->lot);

    $row = EntitlementLedger::create([
        'entitlement_id' => $ent->id,
        'member_id' => $this->member->id,
        'delta' => 5,
        'reason' => LedgerReason::Purchase,
        'balance_after' => 5,
    ]);

    $fresh = EntitlementLedger::query()->findOrFail($row->id);

    expect($fresh->reason)->toBeInstanceOf(LedgerReason::class);
    expect($fresh->reason)->toBe(LedgerReason::Purchase);
});

it('round-trips every LedgerReason case on a ledger row', function () {
    $ent = makeEnumEntitlement($this->lot);

    foreach (LedgerReason::cases() as $case) {
        $row = EntitlementLedger::create([
            'entitlement_id' => $ent->id,
            'member_id' => $this->member->id,
            // Sign convention isn't enforced here — balance_after kept >= 0 for the CHECK.
            'delta' => $case === LedgerReason::Redeem || $case === LedgerReason::Expire ? -1 : 1,
            'reason' => $case,
            'balance_after' => 1,
        ]);

        $fresh = EntitlementLedger::query()->findOrFail($row->id);
        expect($fresh->reason)->toBe($case);
        expect($fresh->reason->value)->toBe($case->value);
    }
});
