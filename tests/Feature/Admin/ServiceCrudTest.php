<?php

declare(strict_types=1);

// Services price-list admin CRUD (owner-only) — the money-wallet reframe of the
// dropped Package catalog. Contracts (ServiceController + Store/UpdateServiceRequest):
//   - store: item_code required + globally unique; name required; price decimal(10,2)
//     string, min:0; branch_id nullable (any-branch); is_active defaults true.
//   - update: item_code unique ignoring self; is_active omitted => false (real toggle).
//   - toggle flips is_active; destroy removes the row.
// Flash is Inertia::flash('toast', ...), so success is asserted via redirect + DB state.
// Money is decimal(10,2) read back through the model cast as a 2dp string (§5.6).

use App\Enums\UserRole;
use App\Models\Branch;
use App\Models\Service;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/** Active, verified owner — the only role allowed through the catalog routes. */
function serviceCrudOwner(): User
{
    return User::factory()->create([
        'role' => UserRole::Owner,
        'is_active' => true,
        'email_verified_at' => now(),
    ]);
}

/**
 * A valid POST /services payload.
 *
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function serviceCrudPayload(array $overrides = []): array
{
    return array_merge([
        'item_code' => 'MASSAGE_60',
        'name' => 'Thai Massage 60',
        'price' => '300.00',
        'branch_id' => null,
        'is_active' => true,
    ], $overrides);
}

beforeEach(function () {
    $this->actingAs(serviceCrudOwner());
});

it('creates a service via POST /services', function () {
    $branch = Branch::create(['name' => 'Svc Branch', 'is_active' => true]);

    $this->post('/services', serviceCrudPayload(['branch_id' => $branch->id]))
        ->assertRedirect(route('services.index'));

    $service = Service::firstWhere('item_code', 'MASSAGE_60');
    expect($service)->not->toBeNull();
    expect($service->name)->toBe('Thai Massage 60');
    expect($service->price)->toBe('300.00'); // exact 2dp string, never a float (§5.6)
    expect($service->branch_id)->toBe($branch->id);
    expect($service->is_active)->toBeTrue();
});

it('defaults a new service to active and any-branch when omitted', function () {
    $this->post('/services', ['item_code' => 'FOOT_30', 'name' => 'Foot 30', 'price' => '150.00'])
        ->assertRedirect(route('services.index'));

    $service = Service::firstWhere('item_code', 'FOOT_30');
    expect($service->is_active)->toBeTrue();
    expect($service->branch_id)->toBeNull();
});

it('requires an item_code and a name', function () {
    $this->post('/services', serviceCrudPayload(['item_code' => '', 'name' => '']))
        ->assertSessionHasErrors(['item_code', 'name']);

    $this->assertDatabaseCount('services', 0);
});

it('rejects a duplicate item_code on create (globally unique)', function () {
    Service::create(serviceCrudPayload());

    $this->post('/services', serviceCrudPayload(['name' => 'Another']))
        ->assertSessionHasErrors(['item_code']);

    $this->assertDatabaseCount('services', 1);
});

it('rejects a negative price (min:0)', function () {
    $this->post('/services', serviceCrudPayload(['price' => '-1.00']))
        ->assertSessionHasErrors(['price']);

    $this->assertDatabaseCount('services', 0);
});

it('rejects a branch_id that does not exist', function () {
    $this->post('/services', serviceCrudPayload(['branch_id' => 999999]))
        ->assertSessionHasErrors(['branch_id']);

    $this->assertDatabaseCount('services', 0);
});

it('updates a service and keeps its own item_code (unique ignores self)', function () {
    $service = Service::create(serviceCrudPayload());

    $this->put(route('services.update', $service), [
        'item_code' => 'MASSAGE_60', // unchanged — must NOT trip unique on self
        'name' => 'Thai Massage 60 (Premium)',
        'price' => '350.00',
        'is_active' => true,
    ])->assertRedirect(route('services.index'));

    $fresh = $service->fresh();
    expect($fresh->name)->toBe('Thai Massage 60 (Premium)');
    expect($fresh->price)->toBe('350.00');
});

it('rejects renaming a service item_code to another existing code', function () {
    Service::create(serviceCrudPayload(['item_code' => 'AAA']));
    $b = Service::create(serviceCrudPayload(['item_code' => 'BBB']));

    $this->put(route('services.update', $b), [
        'item_code' => 'AAA',
        'name' => 'Clash',
        'price' => '100.00',
        'is_active' => true,
    ])->assertSessionHasErrors(['item_code']);

    expect($b->fresh()->item_code)->toBe('BBB');
});

it('deactivates a service when is_active is omitted on update (unchecked = deactivate)', function () {
    $service = Service::create(serviceCrudPayload(['is_active' => true]));

    $this->put(route('services.update', $service), [
        'item_code' => 'MASSAGE_60',
        'name' => 'Thai Massage 60',
        'price' => '300.00',
        // is_active intentionally omitted → UpdateServiceRequest defaults it false.
    ])->assertRedirect(route('services.index'));

    expect($service->fresh()->is_active)->toBeFalse();
});

it('toggles is_active both ways via PATCH /services/{service}/toggle', function () {
    $service = Service::create(serviceCrudPayload(['is_active' => true]));

    $this->patch(route('services.toggle', $service))->assertRedirect();
    expect($service->fresh()->is_active)->toBeFalse();

    $this->patch(route('services.toggle', $service))->assertRedirect();
    expect($service->fresh()->is_active)->toBeTrue();
});

it('deletes a service via DELETE /services/{service}', function () {
    $service = Service::create(serviceCrudPayload());

    $this->delete(route('services.destroy', $service))
        ->assertRedirect(route('services.index'));

    $this->assertDatabaseMissing('services', ['id' => $service->id]);
});
