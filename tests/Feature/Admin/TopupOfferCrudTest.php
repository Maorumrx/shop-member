<?php

declare(strict_types=1);

// Top-up offers (sell-screen presets) admin CRUD (owner-only). Contracts
// (TopupOfferController + Store/UpdateTopupOfferRequest):
//   - store: name required; amount decimal(10,2) string, gt:0; bonus min:0, defaults 0
//     when blank; is_active defaults true; sort_order defaults 0.
//   - update: is_active omitted => false (real toggle).
//   - toggle flips is_active; destroy removes the row.
// Managed inline on the index (no dedicated create/edit pages), like Branches.
// Money is decimal(10,2) read back through the model cast as a 2dp string (§5.6).

use App\Enums\UserRole;
use App\Models\TopupOffer;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/** Active, verified owner — the only role allowed through the catalog routes. */
function offerCrudOwner(): User
{
    return User::factory()->create([
        'role' => UserRole::Owner,
        'is_active' => true,
        'email_verified_at' => now(),
    ]);
}

/**
 * A valid POST /topup-offers payload.
 *
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function offerCrudPayload(array $overrides = []): array
{
    return array_merge([
        'name' => 'Pay 10,000 get 1,000',
        'amount' => '10000.00',
        'bonus' => '1000.00',
        'is_active' => true,
        'sort_order' => 1,
    ], $overrides);
}

beforeEach(function () {
    $this->actingAs(offerCrudOwner());
});

it('creates a preset via POST /topup-offers', function () {
    $this->post('/topup-offers', offerCrudPayload())
        ->assertRedirect(route('topup-offers.index'));

    $offer = TopupOffer::firstWhere('name', 'Pay 10,000 get 1,000');
    expect($offer)->not->toBeNull();
    expect($offer->amount)->toBe('10000.00');
    expect($offer->bonus)->toBe('1000.00');
    expect($offer->is_active)->toBeTrue();
    expect($offer->sort_order)->toBe(1);
});

it('defaults bonus to 0 and offer to active when omitted', function () {
    $this->post('/topup-offers', ['name' => 'No bonus preset', 'amount' => '5000.00'])
        ->assertRedirect(route('topup-offers.index'));

    $offer = TopupOffer::firstWhere('name', 'No bonus preset');
    expect($offer->bonus)->toBe('0.00');
    expect($offer->is_active)->toBeTrue();
    expect($offer->sort_order)->toBe(0);
});

it('requires a name', function () {
    $this->post('/topup-offers', offerCrudPayload(['name' => '']))
        ->assertSessionHasErrors(['name']);

    $this->assertDatabaseCount('topup_offers', 0);
});

it('rejects a non-positive amount (gt:0)', function () {
    $this->post('/topup-offers', offerCrudPayload(['amount' => '0.00']))
        ->assertSessionHasErrors(['amount']);

    $this->assertDatabaseCount('topup_offers', 0);
});

it('rejects a negative bonus (min:0)', function () {
    $this->post('/topup-offers', offerCrudPayload(['bonus' => '-1.00']))
        ->assertSessionHasErrors(['bonus']);

    $this->assertDatabaseCount('topup_offers', 0);
});

it('updates a preset via PUT /topup-offers/{topupOffer}', function () {
    $offer = TopupOffer::create(offerCrudPayload());

    $this->put(route('topup-offers.update', $offer), [
        'name' => 'Pay 10,000 get 1,500',
        'amount' => '10000.00',
        'bonus' => '1500.00',
        'is_active' => true,
        'sort_order' => 2,
    ])->assertRedirect(route('topup-offers.index'));

    $fresh = $offer->fresh();
    expect($fresh->name)->toBe('Pay 10,000 get 1,500');
    expect($fresh->bonus)->toBe('1500.00');
    expect($fresh->sort_order)->toBe(2);
});

it('deactivates a preset when is_active is omitted on update (unchecked = deactivate)', function () {
    $offer = TopupOffer::create(offerCrudPayload(['is_active' => true]));

    $this->put(route('topup-offers.update', $offer), [
        'name' => 'Pay 10,000 get 1,000',
        'amount' => '10000.00',
        'bonus' => '1000.00',
        // is_active omitted → defaults false.
    ])->assertRedirect(route('topup-offers.index'));

    expect($offer->fresh()->is_active)->toBeFalse();
});

it('toggles is_active both ways via PATCH /topup-offers/{topupOffer}/toggle', function () {
    $offer = TopupOffer::create(offerCrudPayload(['is_active' => true]));

    $this->patch(route('topup-offers.toggle', $offer))->assertRedirect();
    expect($offer->fresh()->is_active)->toBeFalse();

    $this->patch(route('topup-offers.toggle', $offer))->assertRedirect();
    expect($offer->fresh()->is_active)->toBeTrue();
});

it('deletes a preset via DELETE /topup-offers/{topupOffer}', function () {
    $offer = TopupOffer::create(offerCrudPayload());

    $this->delete(route('topup-offers.destroy', $offer))
        ->assertRedirect(route('topup-offers.index'));

    $this->assertDatabaseMissing('topup_offers', ['id' => $offer->id]);
});
