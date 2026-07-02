<?php

declare(strict_types=1);

// DB-engine-dependent integrity for the credit-wallet schema (§5.4, §5.6). All are
// MariaDB/MySQL-only and SKIPPED on sqlite: the migrations guard the CHECK statements
// with `driver !== 'sqlite'`, and sqlite FK/RESTRICT semantics differ. These document
// the production contract:
//   - chk_credit_ledger_balance : balance_after >= 0
//   - chk_credit_lots_amounts   : all money >= 0, paid_remaining <= amount_paid,
//                                 bonus_remaining <= bonus_amount
//   - members RESTRICT FK       : a member owning credit_lots cannot be hard-deleted
//
// Rows are hand-built here (NOT via WalletService) deliberately — the whole point is
// to poke the DB with values the service would never emit, to prove the DB itself
// rejects them.

use App\Enums\CreditLotStatus;
use App\Enums\CreditSource;
use App\Models\CreditLot;
use App\Models\Member;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

/** A plain member to own the lot/ledger rows under test. */
function schemaMember(): Member
{
    return Member::create(['name' => 'Schema Member', 'is_active' => true]);
}

/**
 * A valid base credit_lots payload; override individual columns to violate a CHECK.
 *
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function schemaLotPayload(Member $member, array $overrides = []): array
{
    return array_merge([
        'member_id' => $member->id,
        'source' => CreditSource::Topup,
        'amount_paid' => '1000.00',
        'bonus_amount' => '100.00',
        'paid_remaining' => '1000.00',
        'bonus_remaining' => '100.00',
        'expires_at' => null,
        'status' => CreditLotStatus::Active,
        'purchased_at' => now(),
        'branch_id' => null,
        'created_by_user_id' => null,
    ], $overrides);
}

it('rejects a negative amount_paid via chk_credit_lots_amounts', function () {
    $member = schemaMember();

    expect(fn () => CreditLot::create(schemaLotPayload($member, [
        'amount_paid' => '-1.00',
        'paid_remaining' => '-1.00',
    ])))->toThrow(Illuminate\Database\QueryException::class);
})->skip(fn () => DB::getDriverName() === 'sqlite', 'CHECK constraint requires MariaDB/MySQL (guarded off on sqlite).');

it('rejects paid_remaining greater than amount_paid via chk_credit_lots_amounts', function () {
    $member = schemaMember();

    // A remaining can never exceed its frozen original — a debit/refund only reduces it.
    expect(fn () => CreditLot::create(schemaLotPayload($member, [
        'amount_paid' => '100.00',
        'paid_remaining' => '150.00',
    ])))->toThrow(Illuminate\Database\QueryException::class);
})->skip(fn () => DB::getDriverName() === 'sqlite', 'CHECK constraint requires MariaDB/MySQL (guarded off on sqlite).');

it('rejects bonus_remaining greater than bonus_amount via chk_credit_lots_amounts', function () {
    $member = schemaMember();

    expect(fn () => CreditLot::create(schemaLotPayload($member, [
        'bonus_amount' => '50.00',
        'bonus_remaining' => '80.00',
    ])))->toThrow(Illuminate\Database\QueryException::class);
})->skip(fn () => DB::getDriverName() === 'sqlite', 'CHECK constraint requires MariaDB/MySQL (guarded off on sqlite).');

it('rejects a negative balance_after via chk_credit_ledger_balance', function () {
    $member = schemaMember();
    $lot = CreditLot::create(schemaLotPayload($member));

    // Insert a raw ledger row with a negative running balance — the CHECK must block it.
    expect(fn () => DB::table('credit_ledger')->insert([
        'member_id' => $member->id,
        'credit_lot_id' => $lot->id,
        'delta' => '-9999.00',
        'reason' => 'debit',
        'balance_after' => '-1.00',
        'booking_id' => null,
        'staff_id' => null,
        'note' => null,
        'created_at' => now(),
    ]))->toThrow(Illuminate\Database\QueryException::class);
})->skip(fn () => DB::getDriverName() === 'sqlite', 'CHECK constraint requires MariaDB/MySQL (guarded off on sqlite).');

it('forbids hard-deleting a member that owns credit_lots (RESTRICT FK)', function () {
    $member = schemaMember();
    CreditLot::create(schemaLotPayload($member));

    // members.id is referenced by credit_lots.member_id ON DELETE RESTRICT (§5.4).
    // A raw hard DELETE (bypassing SoftDeletes) must be blocked by the FK.
    expect(fn () => DB::table('members')->where('id', $member->id)->delete())
        ->toThrow(Illuminate\Database\QueryException::class);

    expect(DB::table('members')->where('id', $member->id)->count())->toBe(1);
})->skip(fn () => DB::getDriverName() === 'sqlite', 'RESTRICT FK enforcement differs on sqlite; requires MariaDB/MySQL.');

it('forces forceDelete() of a member with lots to fail under RESTRICT', function () {
    $member = schemaMember();
    CreditLot::create(schemaLotPayload($member));

    // forceDelete() issues a real DELETE through Eloquent — still blocked by the FK.
    expect(fn () => $member->forceDelete())
        ->toThrow(Illuminate\Database\QueryException::class);

    expect(Member::withTrashed()->whereKey($member->id)->exists())->toBeTrue();
})->skip(fn () => DB::getDriverName() === 'sqlite', 'RESTRICT FK enforcement differs on sqlite; requires MariaDB/MySQL.');
