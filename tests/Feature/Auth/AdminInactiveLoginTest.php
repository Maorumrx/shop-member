<?php

declare(strict_types=1);

// Phase 2 auth — admin (`web`/`users`) login honours users.is_active.
// Fortify::authenticateUsing rejects a deactivated admin/staff even with the
// correct password (§3.2). Driven through the real Fortify login POST route,
// matching tests/Feature/Auth/AuthenticationTest.php (route `login.store`).
// See App\Providers\FortifyServiceProvider.

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('rejects a deactivated user even with the correct password', function () {
    // UserFactory seeds password = 'password'.
    $user = User::factory()->create([
        'role' => UserRole::Staff,
        'is_active' => false,
    ]);

    $this->post(route('login.store'), [
        'email' => $user->email,
        'password' => 'password',
    ]);

    // is_active = false fails the authenticateUsing callback -> not signed in.
    $this->assertGuest();
});

it('lets an active user log in with the correct password', function () {
    $user = User::factory()->create([
        'role' => UserRole::Staff,
        'is_active' => true,
    ]);

    $response = $this->post(route('login.store'), [
        'email' => $user->email,
        'password' => 'password',
    ]);

    $this->assertAuthenticated();
    $response->assertRedirect(route('dashboard', absolute: false));
});

it('still rejects an active user with the wrong password', function () {
    $user = User::factory()->create([
        'role' => UserRole::Owner,
        'is_active' => true,
    ]);

    $this->post(route('login.store'), [
        'email' => $user->email,
        'password' => 'wrong-password',
    ]);

    $this->assertGuest();
});

it('rejects a deactivated owner with the correct password', function () {
    $owner = User::factory()->create([
        'role' => UserRole::Owner,
        'is_active' => false,
    ]);

    $this->post(route('login.store'), [
        'email' => $owner->email,
        'password' => 'password',
    ]);

    $this->assertGuest();
});
