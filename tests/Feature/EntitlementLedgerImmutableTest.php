<?php

declare(strict_types=1);

// Phase 1 staged test — copied to tests/Feature/ and run AFTER scaffold via docker/phase1.sh.
// Covers EntitlementLedger append-only guard (architecture.md §3.8, §5.2) — the
// booted() updating/deleting hooks that throw RuntimeException.

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

/**
 * Minimal member → lot → entitlement → ledger-row chain for the immutability tests.
 */
function makeLedgerRow(): EntitlementLedger
{
    $branch = Branch::create(['name' => 'Ledger Branch '.uniqid(), 'is_active' => true]);
    $member = Member::create(['name' => 'Ledger Member', 'is_active' => true]);

    $lot = MemberPackage::create([
        'member_id' => $member->id,
        'branch_id' => $branch->id,
        'purchased_at' => now(),
        'expires_at' => now()->addMonth(),
        'price_paid' => '500.00',
        'status' => EntitlementStatus::Active,
    ]);

    $ent = Entitlement::create([
        'member_package_id' => $lot->id,
        'member_id' => $member->id,
        'item_code' => 'MASSAGE_60',
        'item_name' => 'Thai Massage 60 min',
        'item_type' => ItemType::Service,
        'qty_total' => 10,
        'qty_remaining' => 10,
        'expires_at' => $lot->expires_at,
        'status' => EntitlementStatus::Active,
    ]);

    return EntitlementLedger::create([
        'entitlement_id' => $ent->id,
        'member_id' => $member->id,
        'delta' => 10,
        'reason' => LedgerReason::Purchase,
        'balance_after' => 10,
        'staff_id' => null,
        'note' => 'initial grant',
    ]);
}

it('allows creating a ledger row', function () {
    $row = makeLedgerRow();

    expect($row->exists)->toBeTrue();
    expect(EntitlementLedger::query()->whereKey($row->id)->exists())->toBeTrue();
});

it('throws RuntimeException when updating a saved ledger row', function () {
    $row = makeLedgerRow();

    expect(fn () => $row->update(['note' => 'tampered']))
        ->toThrow(RuntimeException::class);
});

it('throws RuntimeException when saving a dirty ledger row', function () {
    $row = makeLedgerRow();
    $row->balance_after = 999;

    // ->save() on an existing dirty model triggers the `updating` event guard.
    expect(fn () => $row->save())
        ->toThrow(RuntimeException::class);
});

it('throws RuntimeException when deleting a ledger row', function () {
    $row = makeLedgerRow();

    expect(fn () => $row->delete())
        ->toThrow(RuntimeException::class);
});

it('leaves the row unchanged in the DB after a blocked update attempt', function () {
    $row = makeLedgerRow();

    try {
        $row->update(['note' => 'tampered']);
    } catch (RuntimeException) {
        // expected — swallow so we can assert persisted state below.
    }

    $fresh = EntitlementLedger::query()->find($row->id);
    expect($fresh)->not->toBeNull();
    expect($fresh->note)->toBe('initial grant');
});

it('leaves the row present in the DB after a blocked delete attempt', function () {
    $row = makeLedgerRow();

    try {
        $row->delete();
    } catch (RuntimeException) {
        // expected.
    }

    expect(EntitlementLedger::query()->whereKey($row->id)->exists())->toBeTrue();
});
