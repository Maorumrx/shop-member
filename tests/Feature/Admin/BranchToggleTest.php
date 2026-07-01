<?php

declare(strict_types=1);

// Phase 3 — Package Catalog admin: Branch is_active toggle (owner-only).
// Endpoint under test: PATCH /branches/{branch}/toggle (BranchController@toggle,
// route branches.toggle). It flips branch.is_active and redirects back(). The route
// lives behind ['auth','verified','role:owner'] in routes/admin.php, so it is
// OWNER-ONLY — EnsureUserRole 403s non-owners and `auth` bounces guests to login.
//
// Contracts under test:
//   - owner can toggle: flips is_active both ways (asserted via DB) and redirects back.
//   - staff CANNOT toggle (owner-only gate) — blocked (302|403) and is_active unchanged.
//   - guest is redirected to login and nothing changes.
//
// Flash is Inertia::flash('toast', ...) — success asserted via redirect + DB state.

use App\Enums\UserRole;
use App\Models\Branch;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/** Active, verified admin operator of the given role. */
function branchToggleUser(UserRole $role): User
{
    return User::factory()->create([
        'role' => $role,
        'is_active' => true,
        'email_verified_at' => now(),
    ]);
}

it('lets an owner toggle a branch is_active both ways', function () {
    $branch = Branch::create(['name' => 'Toggle Branch', 'is_active' => true]);

    // true → false
    $this->actingAs(branchToggleUser(UserRole::Owner))
        ->patch(route('branches.toggle', $branch))
        ->assertRedirect(); // back()

    $this->assertDatabaseHas('branches', ['id' => $branch->id, 'is_active' => false]);

    // false → true (proves it flips both ways)
    $this->actingAs(branchToggleUser(UserRole::Owner))
        ->patch(route('branches.toggle', $branch))
        ->assertRedirect(); // back()

    $this->assertDatabaseHas('branches', ['id' => $branch->id, 'is_active' => true]);
});

it('forbids a staff user from toggling a branch (owner-only) and leaves it unchanged', function () {
    $branch = Branch::create(['name' => 'Staff Blocked', 'is_active' => true]);

    // Owner-only gate: EnsureUserRole 403s the staff user. Tolerate 302|403 in case
    // the gate ever redirects instead of forbidding — either way the request is blocked.
    $response = $this->actingAs(branchToggleUser(UserRole::Staff))
        ->patch(route('branches.toggle', $branch));

    expect(in_array($response->status(), [302, 403], true))->toBeTrue();

    // is_active is UNCHANGED (still true).
    $this->assertDatabaseHas('branches', ['id' => $branch->id, 'is_active' => true]);
});

it('redirects a guest to login and changes nothing', function () {
    $branch = Branch::create(['name' => 'Guest Blocked', 'is_active' => true]);

    $this->patch(route('branches.toggle', $branch))
        ->assertRedirect(route('login'));

    $this->assertDatabaseHas('branches', ['id' => $branch->id, 'is_active' => true]);
});
