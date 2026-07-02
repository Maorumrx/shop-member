<?php

declare(strict_types=1);

// Manual wallet actions on a member (MemberWalletController, the money-wallet reframe
// of the dropped RedemptionController):
//   - charge/refund POST behind ['auth','verified','role:owner,staff'];
//   - adjust POST behind ['auth','verified','role:owner'] (OWNER-ONLY, highest trust).
// A domain failure (insufficient / unpriced / over-refund / below-zero adjust) is a
// ValidationException → 422-equivalent surfaced as a session error (Inertia form
// error), NEVER a 500, and NOTHING is written (the whole txn rolled back).
//
// Members are seeded with credit ONLY via WalletService::topUp so the ledger chain is
// honest. Money is decimal(10,2) STRINGS (§5.6).

use App\Enums\CreditLedgerReason;
use App\Enums\CreditSource;
use App\Enums\UserRole;
use App\Models\CreditLedger;
use App\Models\Member;
use App\Models\Service;
use App\Models\User;
use App\Services\Wallet\WalletService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/** Active, verified admin operator of the given role. */
function walletEpUser(UserRole $role): User
{
    return User::factory()->create([
        'role' => $role,
        'is_active' => true,
        'email_verified_at' => now(),
    ]);
}

/** A plain active member. */
function walletEpMember(array $overrides = []): Member
{
    return Member::create(array_merge([
        'name' => 'Wallet Action Member',
        'phone' => '0890000000',
        'is_active' => true,
    ], $overrides));
}

/** Seed spendable credit honestly through the money authority. */
function walletEpTopUp(Member $member, string $paid, string $bonus = '0.00'): void
{
    app(WalletService::class)->topUp($member, $paid, $bonus, CreditSource::Topup, null);
}

/** The member's current spendable balance (decimal-2 string). */
function walletEpBalance(Member $member): string
{
    return app(WalletService::class)->balance($member);
}

/** An active priced service. */
function walletEpService(string $code = 'MASSAGE_60', string $price = '300.00'): Service
{
    return Service::create([
        'item_code' => $code,
        'name' => 'Thai Massage 60',
        'price' => $price,
        'branch_id' => null,
        'is_active' => true,
    ]);
}

// ---------------------------------------------------------------------------
// charge
// ---------------------------------------------------------------------------

it('lets an owner charge a service price and debits the wallet', function () {
    $member = walletEpMember();
    walletEpTopUp($member, '1000.00');
    walletEpService('MASSAGE_60', '300.00');

    $this->actingAs(walletEpUser(UserRole::Owner))
        ->post(route('members.wallet.charge', $member), ['item_code' => 'MASSAGE_60'])
        ->assertRedirect(route('members.show', $member));

    expect(walletEpBalance($member))->toBe('700.00');
    $debit = CreditLedger::query()->where('reason', CreditLedgerReason::Debit)->sole();
    expect($debit->delta)->toBe('-300.00');
});

it('lets a staff user charge a service price (owner+staff surface)', function () {
    $member = walletEpMember();
    walletEpTopUp($member, '1000.00');
    walletEpService('MASSAGE_60', '300.00');

    $this->actingAs(walletEpUser(UserRole::Staff))
        ->post(route('members.wallet.charge', $member), ['item_code' => 'MASSAGE_60'])
        ->assertRedirect(route('members.show', $member));

    expect(walletEpBalance($member))->toBe('700.00');
});

it('returns a 422 field error and writes nothing when the balance is insufficient', function () {
    $member = walletEpMember();
    walletEpTopUp($member, '100.00');       // below the price
    walletEpService('MASSAGE_60', '300.00');

    $this->actingAs(walletEpUser(UserRole::Owner))
        ->post(route('members.wallet.charge', $member), ['item_code' => 'MASSAGE_60'])
        ->assertSessionHasErrors(['item_code']);

    // Nothing debited — the whole txn rolled back.
    expect(walletEpBalance($member))->toBe('100.00');
    expect(CreditLedger::query()->where('reason', CreditLedgerReason::Debit)->count())->toBe(0);
});

it('returns a 422 field error when the service is not priced (no active service)', function () {
    $member = walletEpMember();
    walletEpTopUp($member, '1000.00');
    // No service row for the code → WalletException → 422.

    $this->actingAs(walletEpUser(UserRole::Owner))
        ->post(route('members.wallet.charge', $member), ['item_code' => 'GHOST'])
        ->assertSessionHasErrors(['item_code']);

    expect(walletEpBalance($member))->toBe('1000.00');
    expect(CreditLedger::query()->where('reason', CreditLedgerReason::Debit)->count())->toBe(0);
});

// ---------------------------------------------------------------------------
// refund
// ---------------------------------------------------------------------------

it('lets an owner refund paid credit', function () {
    $member = walletEpMember();
    walletEpTopUp($member, '1000.00', '200.00'); // balance 1200 (paid 1000 + bonus 200)

    $this->actingAs(walletEpUser(UserRole::Owner))
        ->post(route('members.wallet.refund', $member), ['amount' => '400.00', 'note' => 'customer changed mind'])
        ->assertRedirect(route('members.show', $member));

    expect(walletEpBalance($member))->toBe('800.00');
    $refund = CreditLedger::query()->where('reason', CreditLedgerReason::Refund)->sole();
    expect($refund->delta)->toBe('-400.00');
});

it('returns a 422 field error and writes nothing when refunding beyond the paid balance', function () {
    $member = walletEpMember();
    walletEpTopUp($member, '300.00'); // paid 300 only

    $this->actingAs(walletEpUser(UserRole::Owner))
        ->post(route('members.wallet.refund', $member), ['amount' => '500.00', 'note' => 'too much'])
        ->assertSessionHasErrors(['amount']);

    expect(walletEpBalance($member))->toBe('300.00');
    expect(CreditLedger::query()->where('reason', CreditLedgerReason::Refund)->count())->toBe(0);
});

// ---------------------------------------------------------------------------
// adjust — OWNER ONLY
// ---------------------------------------------------------------------------

it('lets an owner grant credit with a positive adjust', function () {
    $member = walletEpMember();

    $this->actingAs(walletEpUser(UserRole::Owner))
        ->post(route('members.wallet.adjust', $member), ['delta' => '500.00', 'note' => 'opening balance'])
        ->assertRedirect(route('members.show', $member));

    expect(walletEpBalance($member))->toBe('500.00');
    $row = CreditLedger::query()->where('reason', CreditLedgerReason::Adjust)->sole();
    expect($row->delta)->toBe('500.00');
});

it('returns a 422 field error and writes nothing on a negative adjust below zero', function () {
    $member = walletEpMember();
    walletEpTopUp($member, '300.00');

    $this->actingAs(walletEpUser(UserRole::Owner))
        ->post(route('members.wallet.adjust', $member), ['delta' => '-500.00', 'note' => 'claw back'])
        ->assertSessionHasErrors(['delta']);

    expect(walletEpBalance($member))->toBe('300.00');
    expect(CreditLedger::query()->where('reason', CreditLedgerReason::Adjust)->count())->toBe(0);
});

it('forbids a staff user from adjusting (owner-only) and writes nothing', function () {
    $member = walletEpMember();

    $response = $this->actingAs(walletEpUser(UserRole::Staff))
        ->post(route('members.wallet.adjust', $member), ['delta' => '500.00', 'note' => 'nope']);

    $response->assertForbidden();
    expect(walletEpBalance($member))->toBe('0.00');
    $this->assertDatabaseCount('credit_ledger', 0);
});

it('redirects a guest to login for a wallet action and writes nothing', function () {
    $member = walletEpMember();
    walletEpTopUp($member, '1000.00');
    walletEpService('MASSAGE_60', '300.00');
    $ledgerBefore = CreditLedger::count();

    $this->post(route('members.wallet.charge', $member), ['item_code' => 'MASSAGE_60'])
        ->assertRedirect(route('login'));

    $this->assertDatabaseCount('credit_ledger', $ledgerBefore);
});
