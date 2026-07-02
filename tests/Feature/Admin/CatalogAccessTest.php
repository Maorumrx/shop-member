<?php

declare(strict_types=1);

// Catalog admin OWNER-ONLY access gate (the money-wallet reframe: the dropped
// Packages catalog is now Services + Top-up offers). Routes live in routes/admin.php
// behind ['auth','verified','role:owner'], loaded under `web` with NO uri prefix, so
// the paths are /branches, /services, /topup-offers directly. EnsureUserRole 403s
// non-owners; `auth` redirects guests to login. Inertia component assertions need no
// JS build.

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;

uses(RefreshDatabase::class);

/** Owner user fixture (active + verified so `auth`/`verified` pass). */
function catalogAccessOwner(): User
{
    return User::factory()->create([
        'role' => UserRole::Owner,
        'is_active' => true,
        'email_verified_at' => now(),
    ]);
}

/** Staff user fixture for the negative (403) gate cases. */
function catalogAccessStaff(): User
{
    return User::factory()->create([
        'role' => UserRole::Staff,
        'is_active' => true,
        'email_verified_at' => now(),
    ]);
}

it('redirects a guest to login for each catalog index', function (string $path) {
    $this->get($path)->assertRedirect(route('login'));
})->with(['/branches', '/services', '/topup-offers']);

it('forbids a staff user on each owner-only catalog index (403)', function (string $path) {
    $this->actingAs(catalogAccessStaff())
        ->get($path)
        ->assertForbidden();
})->with(['/branches', '/services', '/topup-offers']);

it('lets an owner view the branches index (Inertia Admin/Branches/Index)', function () {
    $this->actingAs(catalogAccessOwner())
        ->get('/branches')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->component('Admin/Branches/Index'));
});

it('lets an owner view the services index (Inertia Admin/Services/Index)', function () {
    $this->actingAs(catalogAccessOwner())
        ->get('/services')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->component('Admin/Services/Index'));
});

it('lets an owner view the top-up offers index (Inertia Admin/TopupOffers/Index)', function () {
    $this->actingAs(catalogAccessOwner())
        ->get('/topup-offers')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->component('Admin/TopupOffers/Index'));
});
