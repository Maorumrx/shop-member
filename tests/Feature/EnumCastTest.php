<?php

declare(strict_types=1);

// Enum casts for the credit-wallet models (the money-wallet reframe of the dropped
// Entitlement enum-cast test): CreditLot::source → CreditSource, CreditLot::status →
// CreditLotStatus, CreditLedger::reason → CreditLedgerReason — all round-trip through
// the DB. The "happy path" uses a REAL WalletService::topUp so the natural casts
// (topup source, active status, topup/bonus reasons) are proven end-to-end; the
// exhaustive per-case round-trips build rows directly to cover every backing value.

use App\Enums\CreditLedgerReason;
use App\Enums\CreditLotStatus;
use App\Enums\CreditSource;
use App\Models\CreditLedger;
use App\Models\CreditLot;
use App\Models\Member;
use App\Services\Wallet\WalletService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->member = Member::create(['name' => 'Enum Member', 'is_active' => true]);
});

/**
 * A base credit_lots payload for the exhaustive round-trips.
 *
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function enumCastLotPayload(Member $member, array $overrides = []): array
{
    return array_merge([
        'member_id' => $member->id,
        'source' => CreditSource::Topup,
        'amount_paid' => '100.00',
        'bonus_amount' => '0.00',
        'paid_remaining' => '100.00',
        'bonus_remaining' => '0.00',
        'expires_at' => null,
        'status' => CreditLotStatus::Active,
        'purchased_at' => now(),
    ], $overrides);
}

it('casts a real top-up lot + rows to their enum instances (happy path via WalletService)', function () {
    $lot = app(WalletService::class)->topUp($this->member, '1000.00', '100.00', CreditSource::Topup, null);

    $freshLot = CreditLot::query()->findOrFail($lot->id);
    expect($freshLot->source)->toBeInstanceOf(CreditSource::class);
    expect($freshLot->source)->toBe(CreditSource::Topup);
    expect($freshLot->status)->toBeInstanceOf(CreditLotStatus::class);
    expect($freshLot->status)->toBe(CreditLotStatus::Active);

    $rows = CreditLedger::query()->where('credit_lot_id', $lot->id)->orderBy('id')->get();
    expect($rows[0]->reason)->toBeInstanceOf(CreditLedgerReason::class);
    expect($rows[0]->reason)->toBe(CreditLedgerReason::Topup);
    expect($rows[1]->reason)->toBe(CreditLedgerReason::Bonus);
});

it('round-trips every CreditSource case on a lot', function () {
    foreach (CreditSource::cases() as $case) {
        $lot = CreditLot::create(enumCastLotPayload($this->member, ['source' => $case]));
        $fresh = CreditLot::query()->findOrFail($lot->id);

        expect($fresh->source)->toBe($case);
        expect($fresh->source->value)->toBe($case->value);
    }
});

it('round-trips every CreditLotStatus case on a lot', function () {
    foreach (CreditLotStatus::cases() as $case) {
        $lot = CreditLot::create(enumCastLotPayload($this->member, ['status' => $case]));
        $fresh = CreditLot::query()->findOrFail($lot->id);

        expect($fresh->status)->toBeInstanceOf(CreditLotStatus::class);
        expect($fresh->status)->toBe($case);
        expect($fresh->status->value)->toBe($case->value);
    }
});

it('round-trips every CreditLedgerReason case on a ledger row', function () {
    $lot = CreditLot::create(enumCastLotPayload($this->member));

    foreach (CreditLedgerReason::cases() as $case) {
        $row = CreditLedger::create([
            'member_id' => $this->member->id,
            'credit_lot_id' => $lot->id,
            // Sign convention isn't enforced here — balance_after kept >= 0 for the CHECK.
            'delta' => in_array($case, [CreditLedgerReason::Debit, CreditLedgerReason::Refund, CreditLedgerReason::Expire], true) ? '-1.00' : '1.00',
            'reason' => $case,
            'balance_after' => '1.00',
            'created_at' => now(),
        ]);

        $fresh = CreditLedger::query()->findOrFail($row->id);
        expect($fresh->reason)->toBeInstanceOf(CreditLedgerReason::class);
        expect($fresh->reason)->toBe($case);
        expect($fresh->reason->value)->toBe($case->value);
    }
});
