<?php

declare(strict_types=1);

// credit_ledger APPEND-ONLY guard (the money-wallet reframe of the dropped
// EntitlementLedger immutability test). The model's booted() updating/deleting hooks
// throw RuntimeException at runtime; corrections are an APPENDED row (refund/adjust),
// never an edit to history. Rows are seeded HONESTLY via WalletService::topUp so the
// ledger chain is real, then the immutability contract is probed on a real row.

use App\Enums\CreditSource;
use App\Models\CreditLedger;
use App\Models\Member;
use App\Services\Wallet\WalletService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/** A real member with a real opening top-up, returning its first ledger row. */
function creditImmutRow(): CreditLedger
{
    $member = Member::create(['name' => 'Ledger Member', 'is_active' => true]);

    app(WalletService::class)->topUp($member, '500.00', '0.00', CreditSource::Topup, null);

    return CreditLedger::query()->where('member_id', $member->id)->orderBy('id')->firstOrFail();
}

it('allows creating a ledger row (append)', function () {
    $row = creditImmutRow();

    expect($row->exists)->toBeTrue();
    expect(CreditLedger::query()->whereKey($row->id)->exists())->toBeTrue();
});

it('throws RuntimeException when updating a saved ledger row', function () {
    $row = creditImmutRow();

    expect(fn () => $row->update(['note' => 'tampered']))
        ->toThrow(RuntimeException::class);
});

it('throws RuntimeException when saving a dirty ledger row', function () {
    $row = creditImmutRow();
    $row->balance_after = '999.00';

    // ->save() on an existing dirty model triggers the `updating` event guard.
    expect(fn () => $row->save())
        ->toThrow(RuntimeException::class);
});

it('throws RuntimeException when deleting a ledger row', function () {
    $row = creditImmutRow();

    expect(fn () => $row->delete())
        ->toThrow(RuntimeException::class);
});

it('leaves the row unchanged in the DB after a blocked update attempt', function () {
    $row = creditImmutRow();
    $originalDelta = (string) $row->delta;

    try {
        $row->update(['delta' => '1.00']);
    } catch (RuntimeException) {
        // expected — swallow so we can assert persisted state below.
    }

    $fresh = CreditLedger::query()->find($row->id);
    expect($fresh)->not->toBeNull();
    expect((string) $fresh->delta)->toBe($originalDelta);
});

it('leaves the row present in the DB after a blocked delete attempt', function () {
    $row = creditImmutRow();

    try {
        $row->delete();
    } catch (RuntimeException) {
        // expected.
    }

    expect(CreditLedger::query()->whereKey($row->id)->exists())->toBeTrue();
});
