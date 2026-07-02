<?php

declare(strict_types=1);

// Sell-credit endpoint: POST members/{member}/topups (TopupController + StoreTopupRequest,
// the money-wallet reframe of the dropped purchase endpoint). Route lives behind
// ['auth','verified','role:owner,staff'] in routes/admin.php (no uri prefix).
//
// Contracts under test:
//   - owner AND staff can sell (302 → members.show + a credit_lot + opening ledger rows);
//     a guest is bounced to login; a members-guard session cannot reach it (no rows).
//   - PRESET path: topup_offer_id resolves the paid/bonus amounts SERVER-SIDE from the
//     offer row — any client-sent amount_paid is IGNORED (a tampered price can't be honoured).
//   - CUSTOM path: amount_paid + bonus_amount mint a lot with exactly those amounts.
//   - selling with an INACTIVE preset → topup_offer_id validation error (scoped exists), no rows.
//   - selling to an INACTIVE member → `member` validator error, no rows.
//   - selling to a SOFT-DELETED member → 404 (route-model binding).
//
// Flash is Inertia::flash('toast', ...); success is the redirect + DB state. Money is
// decimal(10,2) read through the model cast as a 2dp string (§5.6).

use App\Enums\UserRole;
use App\Models\CreditLedger;
use App\Models\CreditLot;
use App\Models\Member;
use App\Models\TopupOffer;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/** Active, verified admin operator (owner or staff) — both may sell. */
function topupEpUser(UserRole $role): User
{
    return User::factory()->create([
        'role' => $role,
        'is_active' => true,
        'email_verified_at' => now(),
    ]);
}

/** A plain active member to sell to. */
function topupEpMember(array $overrides = []): Member
{
    return Member::create(array_merge([
        'name' => 'Topup Buyer',
        'phone' => '0820000000',
        'is_active' => true,
    ], $overrides));
}

/** An active preset (10,000 → +1,000). */
function topupEpOffer(array $overrides = []): TopupOffer
{
    return TopupOffer::create(array_merge([
        'name' => 'Pay 10,000 get 1,000',
        'amount' => '10000.00',
        'bonus' => '1000.00',
        'is_active' => true,
        'sort_order' => 1,
    ], $overrides));
}

it('lets an owner sell credit via the custom path (redirect to show + rows minted)', function () {
    $member = topupEpMember();

    $this->actingAs(topupEpUser(UserRole::Owner))
        ->post(route('members.topups.store', $member), [
            'amount_paid' => '5000.00',
            'bonus_amount' => '500.00',
        ])
        ->assertRedirect(route('members.show', $member));

    $lot = CreditLot::query()->where('member_id', $member->id)->sole();
    expect($lot->amount_paid)->toBe('5000.00');
    expect($lot->bonus_amount)->toBe('500.00');
    expect($lot->paid_remaining)->toBe('5000.00');
    expect($lot->bonus_remaining)->toBe('500.00');

    // Opening ledger rows: topup (+5000) then bonus (+500).
    expect(CreditLedger::query()->where('member_id', $member->id)->count())->toBe(2);
});

it('lets a staff user sell credit (members surface is owner+staff)', function () {
    $member = topupEpMember();

    $this->actingAs(topupEpUser(UserRole::Staff))
        ->post(route('members.topups.store', $member), ['amount_paid' => '1000.00'])
        ->assertRedirect(route('members.show', $member));

    $this->assertDatabaseCount('credit_lots', 1);
});

it('resolves preset amounts server-side and ignores a tampered client amount', function () {
    $member = topupEpMember();
    $offer = topupEpOffer();

    $this->actingAs(topupEpUser(UserRole::Owner))
        ->post(route('members.topups.store', $member), [
            'topup_offer_id' => $offer->id,
            // A hostile client tries to pay only 5 — it MUST be ignored.
            'amount_paid' => '5.00',
            'bonus_amount' => '9999.00',
        ])
        ->assertRedirect(route('members.show', $member));

    $lot = CreditLot::query()->where('member_id', $member->id)->sole();
    // The lot carries the OFFER's amounts, not the client's.
    expect($lot->amount_paid)->toBe('10000.00');
    expect($lot->bonus_amount)->toBe('1000.00');
});

it('redirects a guest to login and mints nothing', function () {
    $member = topupEpMember();

    $this->post(route('members.topups.store', $member), ['amount_paid' => '1000.00'])
        ->assertRedirect(route('login'));

    $this->assertDatabaseCount('credit_lots', 0);
});

it('does not let a members-guard session reach the admin sell route', function () {
    $member = topupEpMember();

    // A members-guard session must NOT sell. In tests actingAs($member,'members') also
    // makes `members` the default guard, so the role gate 403s; a real web session
    // would redirect to login. Either way it's blocked; tolerate 302|403.
    $response = $this->actingAs($member, 'members')
        ->post(route('members.topups.store', $member), ['amount_paid' => '1000.00']);

    expect(in_array($response->status(), [302, 403], true))->toBeTrue();
    $this->assertDatabaseCount('credit_lots', 0);
    $this->assertDatabaseCount('credit_ledger', 0);
});

it('rejects selling with an inactive preset (scoped exists) and mints nothing', function () {
    $member = topupEpMember();
    $offer = topupEpOffer(['is_active' => false]);

    $this->actingAs(topupEpUser(UserRole::Owner))
        ->post(route('members.topups.store', $member), ['topup_offer_id' => $offer->id])
        ->assertSessionHasErrors(['topup_offer_id']);

    $this->assertDatabaseCount('credit_lots', 0);
});

it('rejects selling to an inactive member with a member validation error', function () {
    $member = topupEpMember(['is_active' => false]);

    $this->actingAs(topupEpUser(UserRole::Owner))
        ->post(route('members.topups.store', $member), ['amount_paid' => '1000.00'])
        ->assertSessionHasErrors(['member']);

    $this->assertDatabaseCount('credit_lots', 0);
});

it('returns 404 when selling to a soft-deleted member (route binding)', function () {
    $member = topupEpMember();
    $member->delete(); // soft delete → excluded by the default SoftDeletes scope.

    $this->actingAs(topupEpUser(UserRole::Owner))
        ->post(route('members.topups.store', $member), ['amount_paid' => '1000.00'])
        ->assertNotFound();

    $this->assertDatabaseCount('credit_lots', 0);
});
