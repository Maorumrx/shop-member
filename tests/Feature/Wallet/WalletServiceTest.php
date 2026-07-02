<?php

declare(strict_types=1);

// Credit-wallet money core — WalletService (the SINGLE money authority, the reframe
// of the dropped PurchaseService + RedemptionService). Calls the service DIRECTLY
// (no HTTP) to prove the atomic, lock-protected, bcmath-exact contract:
//   - topUp: one credit_lots row + a `topup` (+bonus) ledger pair; remainings start
//     == originals; running balance_after; validation (negative / empty) writes ZERO.
//   - debit FIFO: oldest-expiry (NULLS LAST) then oldest-purchase first; WITHIN a lot
//     bonus_remaining burns BEFORE paid_remaining; one ledger row per touched lot;
//     used_up flip at 0; running balance_after; multi-lot spanning.
//   - insufficient: InsufficientCreditException BEFORE any write (full rollback,
//     unchanged balance, no orphan ledger row).
//   - refund: reduces paid_remaining ONLY, capped at paid (never bonus, never > paid).
//   - adjust: + mints an adjustment lot holding value as BONUS (NOT refundable as cash);
//     - debits FIFO and rejects below zero; zero delta rejected.
//   - THE INVARIANT, asserted explicitly: balance() == SUM(active lot
//     paid_remaining+bonus_remaining) == latest credit_ledger.balance_after, always >= 0.
//
// Money is decimal(10,2) STRINGS end-to-end (§5.6). Members get their credit ONLY via
// WalletService::topUp (never hand-inserted ledger rows) so the invariant stays honest.
//
// sqlite caveat: the suite runs on sqlite :memory:, whose lockForUpdate() compiles to
// a no-op and which skips the guarded CHECK constraints — the SERVICE-level atomicity
// + ordering contract is what is exercised here (DB CHECKs live in SchemaConstraintsTest).

use App\Enums\CreditLedgerReason;
use App\Enums\CreditLotStatus;
use App\Enums\CreditSource;
use App\Exceptions\InsufficientCreditException;
use App\Exceptions\WalletException;
use App\Models\CreditLedger;
use App\Models\CreditLot;
use App\Models\Member;
use App\Models\User;
use App\Services\Wallet\WalletService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/** The single money authority under test. */
function walletSvc(): WalletService
{
    return app(WalletService::class);
}

/** A plain active member (the wallet owner). */
function walletMember(array $overrides = []): Member
{
    return Member::create(array_merge([
        'name' => 'Wallet Owner',
        'phone' => '0870000000',
        'is_active' => true,
    ], $overrides));
}

/** Active, verified operator recorded as lot creator / ledger.staff_id. */
function walletStaff(): User
{
    return User::factory()->create([
        'role' => \App\Enums\UserRole::Staff,
        'is_active' => true,
        'email_verified_at' => now(),
    ]);
}

/**
 * The wallet invariant, computed three independent ways and asserted equal + >= 0:
 *   balance() == SUM(active, non-expired lot paid_remaining+bonus_remaining)
 *            == the member's latest credit_ledger.balance_after.
 */
function walletAssertInvariant(Member $member): void
{
    $balance = walletSvc()->balance($member);

    // Independent SUM over the active lot set (bcmath, never float).
    $sum = '0.00';
    foreach (CreditLot::query()->where('member_id', $member->id)->where('status', CreditLotStatus::Active)->get() as $lot) {
        // Skip date-expired lots (balance() excludes them); expiry is off in these tests.
        if ($lot->expires_at !== null && $lot->expires_at->lessThanOrEqualTo(now())) {
            continue;
        }
        $sum = bcadd($sum, bcadd((string) $lot->paid_remaining, (string) $lot->bonus_remaining, 2), 2);
    }

    $latest = CreditLedger::query()
        ->where('member_id', $member->id)
        ->orderByDesc('id')
        ->value('balance_after');

    expect(bccomp($balance, $sum, 2))->toBe(0);
    expect(bccomp($balance, (string) $latest, 2))->toBe(0);
    expect(bccomp($balance, '0', 2))->toBeGreaterThanOrEqual(0);
}

// ---------------------------------------------------------------------------
// topUp
// ---------------------------------------------------------------------------

it('mints a lot plus a topup and a bonus ledger row with running balance_after', function () {
    $member = walletMember();
    $staff = walletStaff();

    $lot = walletSvc()->topUp($member, '10000.00', '1000.00', CreditSource::Topup, $staff);

    // The lot: originals frozen, remainings == originals, active, source topup.
    $fresh = CreditLot::findOrFail($lot->id);
    expect($fresh->amount_paid)->toBe('10000.00');
    expect($fresh->bonus_amount)->toBe('1000.00');
    expect($fresh->paid_remaining)->toBe('10000.00');
    expect($fresh->bonus_remaining)->toBe('1000.00');
    expect($fresh->status)->toBe(CreditLotStatus::Active);
    expect($fresh->source)->toBe(CreditSource::Topup);
    expect($fresh->created_by_user_id)->toBe($staff->id);

    // Two ledger rows: topup (+paid) then bonus (+bonus), each carrying its running total.
    $rows = CreditLedger::query()->where('credit_lot_id', $lot->id)->orderBy('id')->get();
    expect($rows)->toHaveCount(2);

    expect($rows[0]->reason)->toBe(CreditLedgerReason::Topup);
    expect($rows[0]->delta)->toBe('10000.00');
    expect($rows[0]->balance_after)->toBe('10000.00');
    expect($rows[0]->staff_id)->toBe($staff->id);

    expect($rows[1]->reason)->toBe(CreditLedgerReason::Bonus);
    expect($rows[1]->delta)->toBe('1000.00');
    expect($rows[1]->balance_after)->toBe('11000.00');

    expect(walletSvc()->balance($member))->toBe('11000.00');
    walletAssertInvariant($member);
});

it('writes only a topup row (no bonus row) when bonus is zero', function () {
    $member = walletMember();

    $lot = walletSvc()->topUp($member, '500.00', '0.00', CreditSource::Topup, null);

    $rows = CreditLedger::query()->where('credit_lot_id', $lot->id)->get();
    expect($rows)->toHaveCount(1);
    expect($rows[0]->reason)->toBe(CreditLedgerReason::Topup);
    expect(CreditLedger::query()->where('reason', CreditLedgerReason::Bonus)->count())->toBe(0);
    expect(walletSvc()->balance($member))->toBe('500.00');
});

it('rejects a negative component and writes nothing', function () {
    $member = walletMember();

    expect(fn () => walletSvc()->topUp($member, '-1.00', '0.00', CreditSource::Topup, null))
        ->toThrow(WalletException::class);

    $this->assertDatabaseCount('credit_lots', 0);
    $this->assertDatabaseCount('credit_ledger', 0);
});

it('rejects an empty top-up (both paid and bonus zero) and writes nothing', function () {
    $member = walletMember();

    expect(fn () => walletSvc()->topUp($member, '0.00', '0.00', CreditSource::Topup, null))
        ->toThrow(WalletException::class);

    $this->assertDatabaseCount('credit_lots', 0);
    $this->assertDatabaseCount('credit_ledger', 0);
});

// ---------------------------------------------------------------------------
// debit — FIFO, bonus-before-paid, used_up, running balance
// ---------------------------------------------------------------------------

it('consumes bonus_remaining BEFORE paid_remaining within a lot', function () {
    $member = walletMember();
    $staff = walletStaff();
    walletSvc()->topUp($member, '300.00', '200.00', CreditSource::Topup, $staff); // balance 500

    $result = walletSvc()->debit($member, '250.00', CreditLedgerReason::Debit, $staff);

    $lot = CreditLot::query()->where('member_id', $member->id)->sole();
    // Bonus 200 fully burned, then 50 of paid.
    expect($lot->bonus_remaining)->toBe('0.00');
    expect($lot->paid_remaining)->toBe('250.00');
    expect($lot->status)->toBe(CreditLotStatus::Active); // 250 still left

    // Exactly one debit ledger row for the single touched lot.
    $debits = CreditLedger::query()->where('reason', CreditLedgerReason::Debit)->get();
    expect($debits)->toHaveCount(1);
    expect($debits[0]->delta)->toBe('-250.00');
    expect($debits[0]->balance_after)->toBe('250.00');
    expect($debits[0]->staff_id)->toBe($staff->id);
    expect($debits[0]->booking_id)->toBeNull();

    // The movement splits the take bonus-first.
    expect($result->movements)->toHaveCount(1);
    expect($result->movements[0]->bonusDelta)->toBe('-200.00');
    expect($result->movements[0]->paidDelta)->toBe('-50.00');
    expect($result->balanceAfter)->toBe('250.00');

    expect(walletSvc()->balance($member))->toBe('250.00');
    walletAssertInvariant($member);
});

it('debits across multiple lots FIFO, one ledger row per lot, flips a drained lot to used_up', function () {
    $member = walletMember();
    $lot1 = walletSvc()->topUp($member, '300.00', '0.00', CreditSource::Topup, null); // oldest
    $lot2 = walletSvc()->topUp($member, '300.00', '0.00', CreditSource::Topup, null); // newest

    // Debit 400 → lot1 fully (300), then 100 from lot2. Balance 600 → 200.
    $result = walletSvc()->debit($member, '400.00', CreditLedgerReason::Debit, null);

    $fresh1 = CreditLot::findOrFail($lot1->id);
    $fresh2 = CreditLot::findOrFail($lot2->id);

    expect($fresh1->paid_remaining)->toBe('0.00');
    expect($fresh1->status)->toBe(CreditLotStatus::UsedUp);
    expect($fresh2->paid_remaining)->toBe('200.00');
    expect($fresh2->status)->toBe(CreditLotStatus::Active);

    // One debit row per touched lot, with the running balance_after.
    $debits = CreditLedger::query()->where('reason', CreditLedgerReason::Debit)->orderBy('id')->get();
    expect($debits)->toHaveCount(2);
    expect($debits[0]->credit_lot_id)->toBe($lot1->id);
    expect($debits[0]->delta)->toBe('-300.00');
    expect($debits[0]->balance_after)->toBe('300.00');
    expect($debits[1]->credit_lot_id)->toBe($lot2->id);
    expect($debits[1]->delta)->toBe('-100.00');
    expect($debits[1]->balance_after)->toBe('200.00');

    expect($result->balanceAfter)->toBe('200.00');
    expect(walletSvc()->balance($member))->toBe('200.00');
    walletAssertInvariant($member);
});

it('orders the FIFO walk by expiry (soonest first, never-expiring last)', function () {
    $member = walletMember();
    // Create out of expiry order to prove the ordering, not the insertion order.
    $later = walletSvc()->topUp($member, '100.00', '0.00', CreditSource::Topup, null, null, now()->addDays(30));
    $never = walletSvc()->topUp($member, '100.00', '0.00', CreditSource::Topup, null, null, null);
    $soon = walletSvc()->topUp($member, '100.00', '0.00', CreditSource::Topup, null, null, now()->addDays(5));

    // First 100: must hit the soonest-expiry lot.
    walletSvc()->debit($member, '100.00', CreditLedgerReason::Debit, null);
    expect(CreditLot::findOrFail($soon->id)->status)->toBe(CreditLotStatus::UsedUp);
    expect(CreditLot::findOrFail($later->id)->status)->toBe(CreditLotStatus::Active);
    expect(CreditLot::findOrFail($never->id)->status)->toBe(CreditLotStatus::Active);

    // Next 100: the dated lot goes before the never-expiring one.
    walletSvc()->debit($member, '100.00', CreditLedgerReason::Debit, null);
    expect(CreditLot::findOrFail($later->id)->status)->toBe(CreditLotStatus::UsedUp);
    expect(CreditLot::findOrFail($never->id)->status)->toBe(CreditLotStatus::Active);

    walletAssertInvariant($member);
});

it('throws InsufficientCreditException before any write and leaves the balance unchanged', function () {
    $member = walletMember();
    walletSvc()->topUp($member, '300.00', '0.00', CreditSource::Topup, null); // balance 300

    $ledgerBefore = CreditLedger::count();

    expect(fn () => walletSvc()->debit($member, '500.00', CreditLedgerReason::Debit, null))
        ->toThrow(InsufficientCreditException::class);

    // Full rollback: no debit row, no lot decrement, unchanged balance.
    expect(CreditLedger::query()->where('reason', CreditLedgerReason::Debit)->count())->toBe(0);
    $this->assertDatabaseCount('credit_ledger', $ledgerBefore);
    expect(CreditLot::query()->where('member_id', $member->id)->sole()->paid_remaining)->toBe('300.00');
    expect(walletSvc()->balance($member))->toBe('300.00');
    walletAssertInvariant($member);
});

it('rejects a non-positive debit amount', function () {
    $member = walletMember();
    walletSvc()->topUp($member, '100.00', '0.00', CreditSource::Topup, null);

    expect(fn () => walletSvc()->debit($member, '0.00', CreditLedgerReason::Debit, null))
        ->toThrow(WalletException::class);
});

// ---------------------------------------------------------------------------
// refund — paid only, capped at paid, never bonus
// ---------------------------------------------------------------------------

it('refunds PAID value only and never touches bonus', function () {
    $member = walletMember();
    $staff = walletStaff();
    walletSvc()->topUp($member, '1000.00', '200.00', CreditSource::Topup, $staff); // balance 1200

    walletSvc()->refund($member, '500.00', $staff, 'goodwill');

    $lot = CreditLot::query()->where('member_id', $member->id)->sole();
    expect($lot->paid_remaining)->toBe('500.00'); // 1000 - 500
    expect($lot->bonus_remaining)->toBe('200.00'); // untouched

    $refunds = CreditLedger::query()->where('reason', CreditLedgerReason::Refund)->get();
    expect($refunds)->toHaveCount(1);
    expect($refunds[0]->delta)->toBe('-500.00');
    expect($refunds[0]->balance_after)->toBe('700.00');
    expect($refunds[0]->note)->toBe('goodwill');

    expect(walletSvc()->balance($member))->toBe('700.00');
    walletAssertInvariant($member);
});

it('caps a refund at the paid balance and cannot dip into bonus', function () {
    $member = walletMember();
    walletSvc()->topUp($member, '1000.00', '200.00', CreditSource::Topup, null); // paid 1000, bonus 200

    // Refund the full paid balance — leaves only the (non-refundable) bonus.
    walletSvc()->refund($member, '1000.00', null, 'full paid back');
    expect(walletSvc()->balance($member))->toBe('200.00');

    $ledgerBefore = CreditLedger::count();

    // One more baht would have to come from bonus — rejected, nothing written.
    expect(fn () => walletSvc()->refund($member, '1.00', null, 'over'))
        ->toThrow(WalletException::class);

    $this->assertDatabaseCount('credit_ledger', $ledgerBefore);
    expect(walletSvc()->balance($member))->toBe('200.00');
    walletAssertInvariant($member);
});

it('rejects a refund exceeding the refundable paid balance and writes nothing', function () {
    $member = walletMember();
    walletSvc()->topUp($member, '300.00', '0.00', CreditSource::Topup, null);

    $ledgerBefore = CreditLedger::count();

    expect(fn () => walletSvc()->refund($member, '500.00', null, 'too much'))
        ->toThrow(WalletException::class);

    $this->assertDatabaseCount('credit_ledger', $ledgerBefore);
    expect(CreditLot::query()->where('member_id', $member->id)->sole()->paid_remaining)->toBe('300.00');
});

// ---------------------------------------------------------------------------
// adjust — signed; + mints a bonus (non-refundable) lot; - debits FIFO
// ---------------------------------------------------------------------------

it('mints an adjustment lot (value held as bonus, not refundable as cash) on a positive adjust', function () {
    $member = walletMember();
    $staff = walletStaff();

    $result = walletSvc()->adjust($member, '500.00', $staff, 'opening balance');

    $lot = CreditLot::findOrFail($result->creditLotId);
    expect($lot->source)->toBe(CreditSource::Adjustment);
    expect($lot->amount_paid)->toBe('0.00');       // no cash
    expect($lot->bonus_amount)->toBe('500.00');    // held as bonus
    expect($lot->bonus_remaining)->toBe('500.00');
    expect($lot->paid_remaining)->toBe('0.00');

    $row = CreditLedger::query()->where('reason', CreditLedgerReason::Adjust)->sole();
    expect($row->delta)->toBe('500.00');
    expect($row->balance_after)->toBe('500.00');
    expect($row->note)->toBe('opening balance');

    expect(walletSvc()->balance($member))->toBe('500.00');

    // The grant is BONUS, so a refund can never claw it back as cash.
    expect(fn () => walletSvc()->refund($member, '1.00', $staff, 'try claw back'))
        ->toThrow(WalletException::class);

    walletAssertInvariant($member);
});

it('debits FIFO on a negative adjust and records reason=adjust', function () {
    $member = walletMember();
    $staff = walletStaff();
    walletSvc()->topUp($member, '300.00', '0.00', CreditSource::Topup, $staff); // balance 300

    walletSvc()->adjust($member, '-100.00', $staff, 'correction');

    expect(walletSvc()->balance($member))->toBe('200.00');
    $row = CreditLedger::query()->where('reason', CreditLedgerReason::Adjust)->sole();
    expect($row->delta)->toBe('-100.00');
    expect($row->balance_after)->toBe('200.00');
    expect($row->note)->toBe('correction');
    walletAssertInvariant($member);
});

it('rejects a negative adjust that would drive the balance below zero and writes nothing', function () {
    $member = walletMember();
    walletSvc()->topUp($member, '300.00', '0.00', CreditSource::Topup, null);

    $ledgerBefore = CreditLedger::count();

    expect(fn () => walletSvc()->adjust($member, '-500.00', null, 'too much'))
        ->toThrow(InsufficientCreditException::class);

    $this->assertDatabaseCount('credit_ledger', $ledgerBefore);
    expect(walletSvc()->balance($member))->toBe('300.00');
});

it('rejects a zero adjustment', function () {
    $member = walletMember();

    expect(fn () => walletSvc()->adjust($member, '0.00', null, 'noop'))
        ->toThrow(WalletException::class);
});

// ---------------------------------------------------------------------------
// The invariant across a mixed sequence
// ---------------------------------------------------------------------------

it('keeps balance == SUM(active lot remainings) == latest balance_after through a mixed sequence', function () {
    $member = walletMember();
    $staff = walletStaff();

    walletSvc()->topUp($member, '1000.00', '200.00', CreditSource::Topup, $staff); // +1200 → 1200
    walletAssertInvariant($member);

    walletSvc()->topUp($member, '500.00', '0.00', CreditSource::Topup, $staff);     // +500  → 1700
    walletAssertInvariant($member);

    walletSvc()->debit($member, '300.00', CreditLedgerReason::Debit, $staff);        // -300  → 1400
    walletAssertInvariant($member);

    walletSvc()->refund($member, '100.00', $staff, 'partial');                       // -100  → 1300
    walletAssertInvariant($member);

    walletSvc()->adjust($member, '50.00', $staff, 'grant');                          // +50   → 1350
    walletAssertInvariant($member);

    walletSvc()->adjust($member, '-20.00', $staff, 'claw');                          // -20   → 1330
    walletAssertInvariant($member);

    // Final headline sanity.
    expect(walletSvc()->balance($member))->toBe('1330.00');
});

it('balance() is 0.00 for a member with no lots', function () {
    $member = walletMember();

    expect(walletSvc()->balance($member))->toBe('0.00');
});
