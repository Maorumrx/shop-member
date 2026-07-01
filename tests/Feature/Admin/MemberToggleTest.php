<?php

declare(strict_types=1);

// Phase 4 — Member admin: Member is_active toggle (owner AND staff).
// Endpoint under test: PATCH /members/{member}/toggle (MemberController@toggle,
// route members.toggle). It flips member.is_active and redirects back(). The route
// lives behind ['auth','verified','role:owner,staff'] in routes/admin.php, so it is
// the OWNER+STAFF front-desk surface — staff are NOT 403 here (unlike the owner-only
// catalog). `auth` bounces guests to login.
//
// Contracts under test:
//   - owner can toggle: flips is_active both ways (asserted via DB) and redirects back.
//   - staff CAN toggle (owner+staff group): flips and redirects back.
//   - guest is redirected to login and nothing changes.
//   - a members-guard session cannot reach the route (blocked, unchanged) — mirrors
//     RedemptionEndpointTest's members-guard gotcha (tolerate 302|403).
//   - soft-deleted member → 404 via route binding (Member uses SoftDeletes).
//
// Flash is Inertia::flash('toast', ...) — success asserted via redirect + DB state.

use App\Enums\UserRole;
use App\Models\Member;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/** Active, verified admin operator (owner or staff) — both reach the members surface. */
function memberToggleUser(UserRole $role): User
{
    return User::factory()->create([
        'role' => $role,
        'is_active' => true,
        'email_verified_at' => now(),
    ]);
}

/** A plain active member. */
function memberToggleMember(array $overrides = []): Member
{
    return Member::create(array_merge([
        'name' => 'Toggle Target',
        'phone' => '0840000000',
        'is_active' => true,
    ], $overrides));
}

it('lets an owner toggle a member is_active both ways', function () {
    $member = memberToggleMember(['is_active' => true]);

    // true → false
    $this->actingAs(memberToggleUser(UserRole::Owner))
        ->patch(route('members.toggle', $member))
        ->assertRedirect(); // back()

    $this->assertDatabaseHas('members', ['id' => $member->id, 'is_active' => false]);

    // false → true (proves it flips both ways)
    $this->actingAs(memberToggleUser(UserRole::Owner))
        ->patch(route('members.toggle', $member))
        ->assertRedirect(); // back()

    $this->assertDatabaseHas('members', ['id' => $member->id, 'is_active' => true]);
});

it('lets a staff user toggle a member (owner+staff surface, not 403)', function () {
    $member = memberToggleMember(['is_active' => true]);

    $this->actingAs(memberToggleUser(UserRole::Staff))
        ->patch(route('members.toggle', $member))
        ->assertRedirect(); // back()

    $this->assertDatabaseHas('members', ['id' => $member->id, 'is_active' => false]);
});

it('redirects a guest to login and changes nothing', function () {
    $member = memberToggleMember(['is_active' => true]);

    $this->patch(route('members.toggle', $member))
        ->assertRedirect(route('login'));

    $this->assertDatabaseHas('members', ['id' => $member->id, 'is_active' => true]);
});

it('does not let a members-guard session reach the member toggle route', function () {
    $member = memberToggleMember(['is_active' => true]);

    // A members-guard session must NOT perform an admin toggle. Note: in tests
    // `actingAs($member, 'members')` also makes `members` the DEFAULT guard, so the
    // admin `auth` middleware sees it as authenticated and the role gate 403s —
    // whereas a real LINE session (default guard `web`) would redirect to login.
    // Either way the request is blocked and nothing changes; tolerate 302|403.
    $response = $this->actingAs($member, 'members')
        ->patch(route('members.toggle', $member));

    expect(in_array($response->status(), [302, 403], true))->toBeTrue();

    // is_active is UNCHANGED (still true).
    $this->assertDatabaseHas('members', ['id' => $member->id, 'is_active' => true]);
});

it('returns 404 when toggling a soft-deleted member', function () {
    // Member uses SoftDeletes — route-model binding excludes trashed rows, so the
    // implicit binding resolves to nothing and the router 404s before the controller.
    $member = memberToggleMember(['is_active' => true]);
    $member->delete();

    $this->actingAs(memberToggleUser(UserRole::Owner))
        ->patch(route('members.toggle', $member))
        ->assertNotFound();
});
