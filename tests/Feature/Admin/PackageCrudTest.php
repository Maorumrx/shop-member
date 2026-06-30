<?php

declare(strict_types=1);

// Phase 3 — Package Catalog admin: Package CRUD + nested package_lines (owner-only).
// Contracts under test (PackageController + Store/UpdatePackageRequest + the shared
// PackageValidationRules trait):
//   - store: creates package + lines atomically; price stored decimal(10,2) as a
//     string (cast decimal:2); valid_days/branch_id nullable.
//   - validation: lines required min:1; duplicate item_code within the payload =>
//     field error lines.{i}.item_code (NOT a 500); item_type in service|addon;
//     qty min:1; price min:0; branch_id must exist if given.
//   - update REPLACES the whole line set (delete-then-recreate), which also makes an
//     item_code swap between two kept lines collision-proof (no QueryException/500).
//   - toggle flips is_active; destroy cascades lines; is_active omitted on update
//     deactivates (unchecked = deactivate contract).
// Flash is Inertia::flash('toast', ...), so success is asserted via redirect + DB state.

use App\Enums\UserRole;
use App\Models\Branch;
use App\Models\Package;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/** Active, verified owner — the only role allowed through the catalog routes. */
function packageCrudOwner(): User
{
    return User::factory()->create([
        'role' => UserRole::Owner,
        'is_active' => true,
        'email_verified_at' => now(),
    ]);
}

/**
 * A valid POST /packages payload. `$overrides` shallow-merge over the header; pass
 * `lines` explicitly to override the default two-line set.
 *
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function packageCrudPayload(array $overrides = []): array
{
    return array_merge([
        'name' => 'Wellness Package',
        'price' => '1500.00',
        'valid_days' => 30,
        'branch_id' => null,
        'is_active' => true,
        'lines' => [
            ['item_code' => 'SVC1', 'item_name' => 'Massage 60', 'item_type' => 'service', 'qty' => 10],
            ['item_code' => 'ADD1', 'item_name' => 'Hot stone', 'item_type' => 'addon', 'qty' => 3],
        ],
    ], $overrides);
}

beforeEach(function () {
    $this->actingAs(packageCrudOwner());
});

it('creates a package and its lines via POST /packages', function () {
    $branch = Branch::create(['name' => 'Pkg Branch', 'is_active' => true]);

    $this->post('/packages', packageCrudPayload(['branch_id' => $branch->id]))
        ->assertRedirect(route('packages.index'));

    $this->assertDatabaseHas('packages', [
        'name' => 'Wellness Package',
        'price' => '1500.00', // decimal(10,2) stored/compared as a 2dp string (§5.6).
        'valid_days' => 30,
        'branch_id' => $branch->id,
        'is_active' => true,
    ]);

    $package = Package::firstWhere('name', 'Wellness Package');
    $this->assertDatabaseCount('package_lines', 2);
    $this->assertDatabaseHas('package_lines', [
        'package_id' => $package->id,
        'item_code' => 'SVC1',
        'item_type' => 'service',
        'qty' => 10,
    ]);
    $this->assertDatabaseHas('package_lines', [
        'package_id' => $package->id,
        'item_code' => 'ADD1',
        'item_type' => 'addon',
        'qty' => 3,
    ]);

    // price round-trips as an exact 2dp decimal string (never a float).
    expect($package->price)->toBe('1500.00');
});

it('allows a null valid_days (never expires) and null branch_id (any-branch)', function () {
    $this->post('/packages', packageCrudPayload(['valid_days' => null, 'branch_id' => null]))
        ->assertRedirect(route('packages.index'));

    $this->assertDatabaseHas('packages', [
        'name' => 'Wellness Package',
        'valid_days' => null,
        'branch_id' => null,
    ]);
});

it('requires at least one line (lines required min:1)', function () {
    $this->post('/packages', packageCrudPayload(['lines' => []]))
        ->assertSessionHasErrors(['lines']);

    $this->assertDatabaseCount('packages', 0);
});

it('rejects a duplicate item_code within the submitted lines (field error, not 500)', function () {
    $payload = packageCrudPayload(['lines' => [
        ['item_code' => 'DUP', 'item_name' => 'First', 'item_type' => 'service', 'qty' => 1],
        ['item_code' => 'DUP', 'item_name' => 'Second', 'item_type' => 'addon', 'qty' => 1],
    ]]);

    // validateUniqueLineCodes() adds the error on the 2nd (index 1) occurrence.
    $this->post('/packages', $payload)
        ->assertSessionHasErrors(['lines.1.item_code']);

    $this->assertDatabaseCount('packages', 0);
});

it('rejects an invalid item_type (must be service|addon)', function () {
    $this->post('/packages', packageCrudPayload(['lines' => [
        ['item_code' => 'BAD', 'item_name' => 'Nope', 'item_type' => 'product', 'qty' => 1],
    ]]))->assertSessionHasErrors(['lines.0.item_type']);
});

it('rejects qty below 1 (qty min:1)', function () {
    $this->post('/packages', packageCrudPayload(['lines' => [
        ['item_code' => 'SVC1', 'item_name' => 'Massage', 'item_type' => 'service', 'qty' => 0],
    ]]))->assertSessionHasErrors(['lines.0.qty']);
});

it('rejects a negative price (price min:0)', function () {
    $this->post('/packages', packageCrudPayload(['price' => '-1.00']))
        ->assertSessionHasErrors(['price']);
});

it('rejects a branch_id that does not exist (exists:branches,id)', function () {
    $this->post('/packages', packageCrudPayload(['branch_id' => 999999]))
        ->assertSessionHasErrors(['branch_id']);
});

it('replaces the line set on update (old lines gone, new lines present)', function () {
    $package = Package::create([
        'name' => 'Replace Me',
        'price' => '1000.00',
        'valid_days' => 30,
        'branch_id' => null,
        'is_active' => true,
    ]);
    $package->lines()->createMany([
        ['item_code' => 'A', 'item_name' => 'Line A', 'item_type' => 'service', 'qty' => 1],
        ['item_code' => 'B', 'item_name' => 'Line B', 'item_type' => 'addon', 'qty' => 1],
    ]);

    $this->put("/packages/{$package->id}", [
        'name' => 'Replace Me',
        'price' => '1000.00',
        'valid_days' => 30,
        'branch_id' => null,
        'is_active' => true,
        'lines' => [
            ['item_code' => 'C', 'item_name' => 'Line C', 'item_type' => 'service', 'qty' => 2],
            ['item_code' => 'D', 'item_name' => 'Line D', 'item_type' => 'service', 'qty' => 4],
            ['item_code' => 'E', 'item_name' => 'Line E', 'item_type' => 'addon', 'qty' => 1],
        ],
    ])->assertRedirect(route('packages.index'));

    // Exactly the new set — old A/B removed, new C/D/E present.
    expect($package->lines()->pluck('item_code')->sort()->values()->all())
        ->toBe(['C', 'D', 'E']);
    $this->assertDatabaseMissing('package_lines', ['package_id' => $package->id, 'item_code' => 'A']);
    $this->assertDatabaseMissing('package_lines', ['package_id' => $package->id, 'item_code' => 'B']);
});

it('swaps two item_codes on update without a 500 (replace-lines is collision-proof)', function () {
    $package = Package::create([
        'name' => 'Swap Codes',
        'price' => '2000.00',
        'valid_days' => null,
        'branch_id' => null,
        'is_active' => true,
    ]);
    $package->lines()->createMany([
        ['item_code' => 'X', 'item_name' => 'Item X', 'item_type' => 'service', 'qty' => 1],
        ['item_code' => 'Y', 'item_name' => 'Item Y', 'item_type' => 'service', 'qty' => 1],
    ]);

    // Swap X<->Y. Under an id-by-id sync this transiently violates the unique
    // (package_id, item_code); the delete-then-recreate replace avoids that.
    $response = $this->put("/packages/{$package->id}", [
        'name' => 'Swap Codes',
        'price' => '2000.00',
        'valid_days' => null,
        'branch_id' => null,
        'is_active' => true,
        'lines' => [
            ['item_code' => 'Y', 'item_name' => 'Item Y', 'item_type' => 'service', 'qty' => 1],
            ['item_code' => 'X', 'item_name' => 'Item X', 'item_type' => 'service', 'qty' => 1],
        ],
    ]);

    expect($response->status())->not->toBe(500);
    $response->assertRedirect(route('packages.index'));

    // Final set is exactly {X, Y} (two rows, swapped names but same codes present).
    expect($package->lines()->pluck('item_code')->sort()->values()->all())->toBe(['X', 'Y']);
    $this->assertDatabaseCount('package_lines', 2);
});

it('toggles is_active via PATCH /packages/{package}/toggle', function () {
    $package = Package::create([
        'name' => 'Toggle Me',
        'price' => '300.00',
        'valid_days' => 30,
        'branch_id' => null,
        'is_active' => true,
    ]);

    $this->patch("/packages/{$package->id}/toggle")->assertRedirect();
    $this->assertDatabaseHas('packages', ['id' => $package->id, 'is_active' => false]);

    $this->patch("/packages/{$package->id}/toggle")->assertRedirect();
    $this->assertDatabaseHas('packages', ['id' => $package->id, 'is_active' => true]);
});

it('deletes a package and cascades its lines via DELETE /packages/{package}', function () {
    $package = Package::create([
        'name' => 'Delete Me',
        'price' => '750.00',
        'valid_days' => 30,
        'branch_id' => null,
        'is_active' => true,
    ]);
    $package->lines()->createMany([
        ['item_code' => 'L1', 'item_name' => 'Line 1', 'item_type' => 'service', 'qty' => 1],
        ['item_code' => 'L2', 'item_name' => 'Line 2', 'item_type' => 'addon', 'qty' => 1],
    ]);

    $this->delete("/packages/{$package->id}")->assertRedirect(route('packages.index'));

    $this->assertDatabaseMissing('packages', ['id' => $package->id]);
    // package_lines.package_id is ON DELETE CASCADE.
    $this->assertDatabaseMissing('package_lines', ['package_id' => $package->id]);
});

it('deactivates a package when is_active is omitted on update (unchecked = deactivate)', function () {
    // UpdatePackageRequest::normalizePackageInput(defaultActive: false): omitting
    // is_active deactivates rather than keeping the stored value.
    $package = Package::create([
        'name' => 'Becomes Inactive',
        'price' => '900.00',
        'valid_days' => 30,
        'branch_id' => null,
        'is_active' => true,
    ]);
    $package->lines()->create([
        'item_code' => 'KEEP', 'item_name' => 'Keep', 'item_type' => 'service', 'qty' => 1,
    ]);

    $this->put("/packages/{$package->id}", [
        'name' => 'Becomes Inactive',
        'price' => '900.00',
        'valid_days' => 30,
        'branch_id' => null,
        // is_active intentionally omitted.
        'lines' => [
            ['item_code' => 'KEEP', 'item_name' => 'Keep', 'item_type' => 'service', 'qty' => 1],
        ],
    ])->assertRedirect(route('packages.index'));

    $this->assertDatabaseHas('packages', ['id' => $package->id, 'is_active' => false]);
});
