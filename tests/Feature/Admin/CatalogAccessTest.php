<?php

declare(strict_types=1);

// Phase 3 — Package Catalog admin (Branches + Packages). Owner-only access gate.
// Routes live in routes/admin.php behind ['auth','verified','role:owner'] and are
// loaded under the `web` group with NO uri prefix, so the paths are /branches and
// /packages directly. EnsureUserRole 403s non-owners; `auth` redirects guests to
// login. Inertia component assertions need no JS build (cf. MemberRouteAccessTest).

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

it('redirects a guest from /branches to login', function () {
    // `auth` runs first and bounces an unauthenticated request to login.
    $this->get('/branches')->assertRedirect(route('login'));
});

it('redirects a guest from /packages to login', function () {
    $this->get('/packages')->assertRedirect(route('login'));
});

it('forbids a staff user on /branches with 403', function () {
    $this->actingAs(catalogAccessStaff())
        ->get('/branches')
        ->assertForbidden();
});

it('forbids a staff user on /packages with 403', function () {
    $this->actingAs(catalogAccessStaff())
        ->get('/packages')
        ->assertForbidden();
});

it('lets an owner view the branches index (Inertia Admin/Branches/Index)', function () {
    $this->actingAs(catalogAccessOwner())
        ->get('/branches')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->component('Admin/Branches/Index'));
});

it('lets an owner view the packages index (Inertia Admin/Packages/Index)', function () {
    $this->actingAs(catalogAccessOwner())
        ->get('/packages')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->component('Admin/Packages/Index'));
});
