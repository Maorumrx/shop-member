<?php

declare(strict_types=1);

// Admin catalog: Branch CRUD (owner-only).
// Contracts under test (BranchController + Store/UpdateBranchRequest):
//   - store: name required + unique:branches,name; is_active defaults true when omitted.
//   - update: name unique ignoring self; is_active omitted => false (real toggle).
//   - destroy: deletes an unused branch; a branch referenced by a booking is FK
//     RESTRICT (bookings.branch_id ON DELETE RESTRICT) — the controller catches the
//     QueryException and flashes an error via back() instead of a 500.
// Flash is Inertia::flash('toast', ...) (not session()->errors), so we assert on the
// redirect + DB state rather than on the flash payload — see flags in the report.

use App\Enums\BookingOrigin;
use App\Enums\BookingStatus;
use App\Enums\UserRole;
use App\Models\Booking;
use App\Models\Branch;
use App\Models\Member;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

/** Active, verified owner — the only role allowed through the catalog routes. */
function branchCrudOwner(): User
{
    return User::factory()->create([
        'role' => UserRole::Owner,
        'is_active' => true,
        'email_verified_at' => now(),
    ]);
}

beforeEach(function () {
    $this->actingAs(branchCrudOwner());
});

it('creates a branch via POST /branches', function () {
    $this->post('/branches', ['name' => 'Siam Branch', 'is_active' => true])
        ->assertRedirect(route('branches.index'));

    $this->assertDatabaseHas('branches', [
        'name' => 'Siam Branch',
        'is_active' => true,
    ]);
});

it('defaults a new branch to active when is_active is omitted', function () {
    // StoreBranchRequest::prepareForValidation merges is_active=true by default.
    $this->post('/branches', ['name' => 'Default Active Branch'])
        ->assertRedirect(route('branches.index'));

    $this->assertDatabaseHas('branches', [
        'name' => 'Default Active Branch',
        'is_active' => true,
    ]);
});

it('requires a branch name', function () {
    $this->post('/branches', ['name' => ''])
        ->assertSessionHasErrors(['name']);

    $this->assertDatabaseCount('branches', 0);
});

it('rejects a duplicate branch name on create (unique:branches,name)', function () {
    Branch::create(['name' => 'Dup Branch', 'is_active' => true]);

    $this->post('/branches', ['name' => 'Dup Branch', 'is_active' => true])
        ->assertSessionHasErrors(['name']);

    // Still only the original row.
    $this->assertDatabaseCount('branches', 1);
});

it('renames a branch via PUT /branches/{branch}', function () {
    $branch = Branch::create(['name' => 'Old Name', 'is_active' => true]);

    $this->put("/branches/{$branch->id}", ['name' => 'New Name', 'is_active' => true])
        ->assertRedirect(route('branches.index'));

    $this->assertDatabaseHas('branches', ['id' => $branch->id, 'name' => 'New Name']);
    $this->assertDatabaseMissing('branches', ['name' => 'Old Name']);
});

it('lets a branch keep its own name on update (unique ignores self)', function () {
    $branch = Branch::create(['name' => 'Keep Me', 'is_active' => true]);

    // Same name + a real change (is_active) — must NOT trip the unique rule on self.
    $this->put("/branches/{$branch->id}", ['name' => 'Keep Me', 'is_active' => false])
        ->assertRedirect(route('branches.index'));

    $this->assertDatabaseHas('branches', [
        'id' => $branch->id,
        'name' => 'Keep Me',
        'is_active' => false,
    ]);
});

it('rejects renaming a branch to another existing name', function () {
    Branch::create(['name' => 'Branch A', 'is_active' => true]);
    $b = Branch::create(['name' => 'Branch B', 'is_active' => true]);

    $this->put("/branches/{$b->id}", ['name' => 'Branch A', 'is_active' => true])
        ->assertSessionHasErrors(['name']);

    $this->assertDatabaseHas('branches', ['id' => $b->id, 'name' => 'Branch B']);
});

it('deactivates a branch when is_active is omitted on update', function () {
    // UpdateBranchRequest::prepareForValidation defaults is_active=false when omitted
    // (an unchecked box deactivates rather than silently keeping the old value).
    $branch = Branch::create(['name' => 'Was Active', 'is_active' => true]);

    $this->put("/branches/{$branch->id}", ['name' => 'Was Active'])
        ->assertRedirect(route('branches.index'));

    $this->assertDatabaseHas('branches', ['id' => $branch->id, 'is_active' => false]);
});

it('deletes an unused branch via DELETE /branches/{branch}', function () {
    $branch = Branch::create(['name' => 'Unused Branch', 'is_active' => true]);

    $this->delete("/branches/{$branch->id}")
        ->assertRedirect(route('branches.index'));

    $this->assertDatabaseMissing('branches', ['id' => $branch->id]);
});

it('does not 500 and keeps a branch that has a booking bound (RESTRICT FK)', function () {
    $branch = Branch::create(['name' => 'Has Booking', 'is_active' => true]);

    // A booking referencing the branch makes bookings.branch_id RESTRICT the delete.
    $member = Member::create(['name' => 'Bound Member', 'phone' => '0899999999', 'is_active' => true]);
    Booking::create([
        'member_id' => $member->id,
        'branch_id' => $branch->id,
        'item_code' => 'MASSAGE_60',
        'item_name' => 'Thai Massage 60',
        'scheduled_start' => now()->addDay(),
        'scheduled_end' => now()->addDay()->addMinutes(60),
        'slot_length_minutes' => 60,
        'status' => BookingStatus::Confirmed,
        'created_via' => BookingOrigin::Member,
        'created_by_user_id' => null,
        'note' => null,
    ]);

    // The controller try/catches the QueryException and redirects via back() — never a 500.
    $response = $this->from(route('branches.index'))->delete("/branches/{$branch->id}");
    expect($response->status())->not->toBe(500);
    $response->assertRedirect();

    // On engines that enforce RESTRICT, the branch survives the blocked delete.
    // sqlite FK/RESTRICT semantics differ from MariaDB, so guard this one assertion
    // the same way SchemaConstraintsTest does.
    if (DB::getDriverName() !== 'sqlite') {
        $this->assertDatabaseHas('branches', ['id' => $branch->id]);
    }
})->skip(
    fn () => DB::getDriverName() === 'sqlite',
    'RESTRICT FK enforcement differs on sqlite; the delete may not throw. Requires MariaDB/MySQL.'
);
