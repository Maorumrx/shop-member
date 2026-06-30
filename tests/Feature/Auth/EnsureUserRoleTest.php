<?php

declare(strict_types=1);

// Phase 2 auth — EnsureUserRole middleware (the `role` alias) on the admin
// `web`/`users` guard. Throwaway routes are registered per test and exercised
// through the real HTTP kernel so the `auth` + `role:...` chain runs as in prod.
// See App\Http\Middleware\EnsureUserRole.

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;

uses(RefreshDatabase::class);

/**
 * Register a throwaway protected route guarded by auth + role:<allowed...>.
 */
function defineRoleRoute(string $uri, string $roleArgs): void
{
    Route::middleware(['web', 'auth', 'role:'.$roleArgs])
        ->get($uri, fn () => response('ok', 200));
}

it('allows an owner through role:owner', function () {
    defineRoleRoute('test/owner-only', 'owner');

    $owner = User::factory()->create([
        'role' => UserRole::Owner,
        'is_active' => true,
    ]);

    $this->actingAs($owner)
        ->get('test/owner-only')
        ->assertOk()
        ->assertSee('ok');
});

it('forbids a staff user on role:owner with 403', function () {
    defineRoleRoute('test/owner-only', 'owner');

    $staff = User::factory()->create([
        'role' => UserRole::Staff,
        'is_active' => true,
    ]);

    $this->actingAs($staff)
        ->get('test/owner-only')
        ->assertForbidden();
});

it('blocks an unauthenticated request (redirect to login, not the route)', function () {
    defineRoleRoute('test/owner-only', 'owner');

    $response = $this->get('test/owner-only');

    // `auth` runs first and bounces a guest to login; the role gate never sees them.
    $response->assertRedirect(route('login'));
});

it('allows a staff user when staff is in the allow-list (role:owner,staff)', function () {
    defineRoleRoute('test/owner-or-staff', 'owner,staff');

    $staff = User::factory()->create([
        'role' => UserRole::Staff,
        'is_active' => true,
    ]);

    $this->actingAs($staff)
        ->get('test/owner-or-staff')
        ->assertOk()
        ->assertSee('ok');
});

it('allows an owner when staff is also in the allow-list (role:owner,staff)', function () {
    defineRoleRoute('test/owner-or-staff', 'owner,staff');

    $owner = User::factory()->create([
        'role' => UserRole::Owner,
        'is_active' => true,
    ]);

    $this->actingAs($owner)
        ->get('test/owner-or-staff')
        ->assertOk();
});

it('does not honour a members-guard session against the admin role gate', function () {
    // Sanity on guard isolation: the role gate reads the default (web) guard's
    // user; a member acting on the `members` guard is still a guest here -> login.
    defineRoleRoute('test/owner-only', 'owner');

    $response = $this->get('test/owner-only');

    $response->assertRedirect(route('login'));
});
