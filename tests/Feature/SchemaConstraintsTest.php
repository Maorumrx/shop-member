<?php

declare(strict_types=1);

// Phase 1 staged test — copied to tests/Feature/ and run AFTER scaffold via docker/phase1.sh.
// Covers DB-engine-dependent integrity (architecture.md §3.7, §5.4):
//   - CHECK constraint chk_ent_qty (qty_remaining >= 0) — MariaDB/MySQL only.
//   - member RESTRICT FK on member_packages — MariaDB/MySQL only.
// Both are SKIPPED on sqlite: the migration guards the CHECK with driver !== 'sqlite',
// and sqlite FK enforcement differs (foreign_keys pragma / RESTRICT semantics).

use App\Enums\EntitlementStatus;
use App\Enums\ItemType;
use App\Models\Branch;
use App\Models\Entitlement;
use App\Models\Member;
use App\Models\MemberPackage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

it('rejects a negative qty_remaining via the chk_ent_qty CHECK constraint', function () {
    $branch = Branch::create(['name' => 'CHK Branch', 'is_active' => true]);
    $member = Member::create(['name' => 'CHK Member', 'is_active' => true]);
    $lot = MemberPackage::create([
        'member_id' => $member->id,
        'branch_id' => $branch->id,
        'purchased_at' => now(),
        'expires_at' => now()->addMonth(),
        'price_paid' => '100.00',
        'status' => EntitlementStatus::Active,
    ]);

    // qty_remaining is an UNSIGNED column AND carries CHECK (qty_remaining >= 0).
    // Writing a negative value must raise a DB query exception on MariaDB/MySQL.
    expect(fn () => Entitlement::create([
        'member_package_id' => $lot->id,
        'member_id' => $member->id,
        'item_code' => 'MASSAGE_60',
        'item_name' => 'Thai Massage 60 min',
        'item_type' => ItemType::Service,
        'qty_total' => 10,
        'qty_remaining' => -1,
        'expires_at' => $lot->expires_at,
        'status' => EntitlementStatus::Active,
    ]))->toThrow(Illuminate\Database\QueryException::class);
})->skip(fn () => DB::getDriverName() === 'sqlite', 'CHECK constraint requires MariaDB/MySQL (guarded off on sqlite in the migration).');

it('forbids hard-deleting a member that owns member_packages (RESTRICT FK)', function () {
    $branch = Branch::create(['name' => 'FK Branch', 'is_active' => true]);
    $member = Member::create(['name' => 'FK Member', 'is_active' => true]);
    MemberPackage::create([
        'member_id' => $member->id,
        'branch_id' => $branch->id,
        'purchased_at' => now(),
        'expires_at' => now()->addMonth(),
        'price_paid' => '100.00',
        'status' => EntitlementStatus::Active,
    ]);

    // members.id is referenced by member_packages.member_id ON DELETE RESTRICT (§5.4).
    // A raw hard DELETE (bypassing SoftDeletes) must be blocked by the FK on MariaDB/MySQL.
    expect(fn () => DB::table('members')->where('id', $member->id)->delete())
        ->toThrow(Illuminate\Database\QueryException::class);

    // The member row must still be present after the blocked delete.
    expect(DB::table('members')->where('id', $member->id)->count())->toBe(1);
})->skip(fn () => DB::getDriverName() === 'sqlite', 'RESTRICT FK enforcement differs on sqlite; requires MariaDB/MySQL.');

it('forces forceDelete() of a member with lots to fail under RESTRICT', function () {
    $branch = Branch::create(['name' => 'Force Branch', 'is_active' => true]);
    $member = Member::create(['name' => 'Force Member', 'is_active' => true]);
    MemberPackage::create([
        'member_id' => $member->id,
        'branch_id' => $branch->id,
        'purchased_at' => now(),
        'expires_at' => now()->addMonth(),
        'price_paid' => '100.00',
        'status' => EntitlementStatus::Active,
    ]);

    // forceDelete() issues a real DELETE through Eloquent — still blocked by the FK.
    expect(fn () => $member->forceDelete())
        ->toThrow(Illuminate\Database\QueryException::class);

    expect(Member::withTrashed()->whereKey($member->id)->exists())->toBeTrue();
})->skip(fn () => DB::getDriverName() === 'sqlite', 'RESTRICT FK enforcement differs on sqlite; requires MariaDB/MySQL.');
