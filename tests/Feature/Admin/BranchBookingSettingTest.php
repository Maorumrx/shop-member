<?php

declare(strict_types=1);

// Phase 7 — Per-branch booking config editor (owner-only).
// Endpoint under test: PUT /branches/{branch}/booking-settings
//   (BranchController@updateBookingSettings, route branches.booking-settings.update).
// It updateOrCreate's the branch_booking_settings row keyed on branch_id and
// redirects to branches.index with a success toast. The route lives behind
// ['auth','verified','role:owner'] in routes/admin.php, so it is OWNER-ONLY —
// EnsureUserRole 403s non-owners and `auth` bounces guests to login.
//
// Contracts under test (UpdateBranchBookingSettingRequest + controller):
//   - upsert semantics: first save creates, later save edits the SAME row
//     (updateOrCreate keyed on branch_id — never a duplicate).
//   - is_bookable is a real toggle: omitted => false (prepareForValidation boolean()).
//   - times arrive as 'H:i' and are normalized to the column's 'H:i:s' TIME shape
//     before persisting (settingsData() appends ':00').
//   - request-layer validation of the slot-grid shape: capacity/length bounds,
//     close_time strictly after open_time, well-formed times, non-negative advance.
//
// MariaDB-only caveat: the CHECK constraints (chk_bbs_capacity/length/advance/window)
// are added ONLY when the driver is not sqlite (see the migration). Under the sqlite
// test DB those DB-level invariants do NOT exist, so the validation asserted here is
// the REQUEST layer (UpdateBranchBookingSettingRequest) — that is the enforcement
// exercised by these tests, independent of engine.
//
// Flash is Inertia::flash('toast', ...) — success asserted via redirect + DB state.

use App\Enums\UserRole;
use App\Models\Branch;
use App\Models\BranchBookingSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;

uses(RefreshDatabase::class);

/** Active, verified admin operator of the given role (auth+verified pass). */
function bookingSettingUser(UserRole $role): User
{
    return User::factory()->create([
        'role' => $role,
        'is_active' => true,
        'email_verified_at' => now(),
    ]);
}

/** A real branch to hang settings off (satisfies the branch_id FK). */
function bookingSettingBranch(string $name = 'Booking Branch'): Branch
{
    return Branch::create(['name' => $name, 'is_active' => true]);
}

/** A valid PUT payload (H:i times, in-range numbers) with per-test overrides. */
function bookingSettingPayload(array $overrides = []): array
{
    return array_merge([
        'is_bookable' => true,
        'slot_capacity' => 2,
        'slot_length_minutes' => 60,
        'open_time' => '10:00',
        'close_time' => '20:00',
        'max_advance_days' => 30,
    ], $overrides);
}

it('lets an owner create booking settings for a branch that has none', function () {
    $branch = bookingSettingBranch();

    $this->actingAs(bookingSettingUser(UserRole::Owner))
        ->put(route('branches.booking-settings.update', $branch), bookingSettingPayload())
        ->assertRedirect(route('branches.index'));

    // Note the 'H:i:s' persistence — the request appends ':00' to the UI's 'H:i'.
    $this->assertDatabaseHas('branch_booking_settings', [
        'branch_id' => $branch->id,
        'is_bookable' => 1,
        'slot_capacity' => 2,
        'slot_length_minutes' => 60,
        'open_time' => '10:00:00',
        'close_time' => '20:00:00',
        'max_advance_days' => 30,
    ]);

    // Exactly one config row was created.
    $this->assertDatabaseCount('branch_booking_settings', 1);
});

it('lets an owner update an existing settings row without duplicating it', function () {
    $branch = bookingSettingBranch();

    // Seed an existing config row (1:1) that the PUT will edit in place.
    $existing = BranchBookingSetting::create([
        'branch_id' => $branch->id,
        'is_bookable' => false,
        'slot_capacity' => 1,
        'slot_length_minutes' => 30,
        'open_time' => '09:00:00',
        'close_time' => '12:00:00',
        'max_advance_days' => 7,
    ]);

    $this->actingAs(bookingSettingUser(UserRole::Owner))
        ->put(route('branches.booking-settings.update', $branch), bookingSettingPayload([
            'is_bookable' => true,
            'slot_capacity' => 4,
            'slot_length_minutes' => 90,
            'open_time' => '08:00',
            'close_time' => '22:00',
            'max_advance_days' => 60,
        ]))
        ->assertRedirect(route('branches.index'));

    // updateOrCreate edited the SAME row — new values, same id.
    $this->assertDatabaseHas('branch_booking_settings', [
        'id' => $existing->id,
        'branch_id' => $branch->id,
        'is_bookable' => 1,
        'slot_capacity' => 4,
        'slot_length_minutes' => 90,
        'open_time' => '08:00:00',
        'close_time' => '22:00:00',
        'max_advance_days' => 60,
    ]);

    // Still exactly ONE row for the branch (not a duplicate insert).
    $this->assertDatabaseCount('branch_booking_settings', 1);
    expect(BranchBookingSetting::where('branch_id', $branch->id)->count())->toBe(1);
});

it('coerces an omitted is_bookable to false on save', function () {
    $branch = bookingSettingBranch();

    // Omit is_bookable entirely — prepareForValidation defaults it to false so an
    // unchecked Switch disables booking rather than silently keeping the old value.
    $payload = bookingSettingPayload();
    unset($payload['is_bookable']);

    $this->actingAs(bookingSettingUser(UserRole::Owner))
        ->put(route('branches.booking-settings.update', $branch), $payload)
        ->assertRedirect(route('branches.index'));

    $this->assertDatabaseHas('branch_booking_settings', [
        'branch_id' => $branch->id,
        'is_bookable' => 0,
    ]);
});

it('rejects slot_capacity below the minimum of 1', function () {
    $branch = bookingSettingBranch();

    $this->actingAs(bookingSettingUser(UserRole::Owner))
        ->put(route('branches.booking-settings.update', $branch), bookingSettingPayload([
            'slot_capacity' => 0,
        ]))
        ->assertSessionHasErrors(['slot_capacity']);

    $this->assertDatabaseCount('branch_booking_settings', 0);
});

it('rejects slot_length_minutes below the minimum of 1', function () {
    $branch = bookingSettingBranch();

    $this->actingAs(bookingSettingUser(UserRole::Owner))
        ->put(route('branches.booking-settings.update', $branch), bookingSettingPayload([
            'slot_length_minutes' => 0,
        ]))
        ->assertSessionHasErrors(['slot_length_minutes']);

    $this->assertDatabaseCount('branch_booking_settings', 0);
});

it('rejects a close_time that is not after open_time', function () {
    $branch = bookingSettingBranch();

    // Inverted window (open 20:00, close 10:00) — after:open_time fails.
    $this->actingAs(bookingSettingUser(UserRole::Owner))
        ->put(route('branches.booking-settings.update', $branch), bookingSettingPayload([
            'open_time' => '20:00',
            'close_time' => '10:00',
        ]))
        ->assertSessionHasErrors(['close_time']);

    $this->assertDatabaseCount('branch_booking_settings', 0);
});

it('rejects a window too short to fit even one slot', function () {
    $branch = bookingSettingBranch();

    // Valid same-day window (10:00–10:30) but SHORTER than the 60-min slot length:
    // after:open_time passes, so withValidator() catches the would-be empty grid.
    $this->actingAs(bookingSettingUser(UserRole::Owner))
        ->put(route('branches.booking-settings.update', $branch), bookingSettingPayload([
            'open_time' => '10:00',
            'close_time' => '10:30',
            'slot_length_minutes' => 60,
        ]))
        ->assertSessionHasErrors(['close_time']);

    $this->assertDatabaseCount('branch_booking_settings', 0);
});

it('rejects a badly formatted time', function () {
    $branch = bookingSettingBranch();

    // '25:00' is not a valid H:i wall-clock time — date_format:H:i fails.
    $this->actingAs(bookingSettingUser(UserRole::Owner))
        ->put(route('branches.booking-settings.update', $branch), bookingSettingPayload([
            'open_time' => '25:00',
        ]))
        ->assertSessionHasErrors(['open_time']);

    $this->assertDatabaseCount('branch_booking_settings', 0);
});

it('rejects a negative max_advance_days', function () {
    $branch = bookingSettingBranch();

    $this->actingAs(bookingSettingUser(UserRole::Owner))
        ->put(route('branches.booking-settings.update', $branch), bookingSettingPayload([
            'max_advance_days' => -1,
        ]))
        ->assertSessionHasErrors(['max_advance_days']);

    $this->assertDatabaseCount('branch_booking_settings', 0);
});

it('forbids a staff user from editing booking settings (owner-only) and writes nothing', function () {
    $branch = bookingSettingBranch();

    // Owner-only gate: EnsureUserRole 403s the staff user. Tolerate 302|403 in case
    // the gate ever redirects instead of forbidding — either way the request is blocked.
    $response = $this->actingAs(bookingSettingUser(UserRole::Staff))
        ->put(route('branches.booking-settings.update', $branch), bookingSettingPayload());

    expect(in_array($response->status(), [302, 403], true))->toBeTrue();

    // No settings row was written by the blocked request.
    $this->assertDatabaseCount('branch_booking_settings', 0);
});

it('redirects a guest to login and writes nothing', function () {
    $branch = bookingSettingBranch();

    // `auth` runs first and bounces an unauthenticated request to login.
    $this->put(route('branches.booking-settings.update', $branch), bookingSettingPayload())
        ->assertRedirect(route('login'));

    $this->assertDatabaseCount('branch_booking_settings', 0);
});

it('includes the booking projection on the branches index for a branch with settings', function () {
    $branch = bookingSettingBranch('Projected Branch');

    BranchBookingSetting::create([
        'branch_id' => $branch->id,
        'is_bookable' => true,
        'slot_capacity' => 3,
        'slot_length_minutes' => 45,
        'open_time' => '11:00:00',
        'close_time' => '19:00:00',
        'max_advance_days' => 14,
    ]);

    // index() paginates via ->through(), so rows land under branches.data.*; the
    // 'booking' projection trims the stored 'H:i:s' times back to 'H:i' for the UI.
    $this->actingAs(bookingSettingUser(UserRole::Owner))
        ->get(route('branches.index'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Admin/Branches/Index')
            ->has('branches.data', 1)
            ->where('branches.data.0.id', $branch->id)
            ->where('branches.data.0.booking.is_bookable', true)
            ->where('branches.data.0.booking.slot_capacity', 3)
            ->where('branches.data.0.booking.slot_length_minutes', 45)
            ->where('branches.data.0.booking.open_time', '11:00')
            ->where('branches.data.0.booking.close_time', '19:00')
            ->where('branches.data.0.booking.max_advance_days', 14));
});
