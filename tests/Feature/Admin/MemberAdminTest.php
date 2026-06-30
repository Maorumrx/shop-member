<?php

declare(strict_types=1);

// Phase 4 — Member admin (MemberController + Store/UpdateMemberRequest,
// architecture.md §3.3). Routes live behind ['auth','verified','role:owner,staff'] in
// routes/admin.php (no uri prefix), so the members surface is OWNER AND STAFF — staff
// are front-desk operators (NOT 403 here, unlike the owner-only catalog).
//
// Contracts under test:
//   - access gate: a guest is redirected to login; both owner AND staff reach
//     GET /members (Inertia Admin/Members/Index) and GET /members/{member}
//     (Admin/Members/Show).
//   - index ?q= searches name OR phone (LIKE) and returns only matching rows.
//   - store creates a member but `line_user_id` is NOT mass-assignable here
//     (StoreMemberRequest whitelists name/phone/email/is_active) — a POST including
//     line_user_id yields a member with line_user_id === null (injection blocked, §3.3).
//   - update toggles is_active (unchecked box → deactivate, UpdateMemberRequest default).
//   - show's `balanceByType` Inertia prop sums qty_remaining per item across the
//     member's ACTIVE, non-expired entitlements (a single grouped query, §6.4).
//
// Flash is Inertia::flash('toast', ...) — asserted via redirect + DB state. Inertia
// component/prop assertions need no JS build (cf. CatalogAccessTest).

use App\Enums\ItemType;
use App\Enums\UserRole;
use App\Models\Member;
use App\Models\Package;
use App\Models\User;
use App\Services\Purchase\PurchaseService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;

uses(RefreshDatabase::class);

/** Active, verified admin operator (owner or staff) — both reach the members surface. */
function memberAdminUser(UserRole $role): User
{
    return User::factory()->create([
        'role' => $role,
        'is_active' => true,
        'email_verified_at' => now(),
    ]);
}

/** A plain active member. */
function memberAdminMember(array $overrides = []): Member
{
    return Member::create(array_merge([
        'name' => 'Admin Member',
        'phone' => '0830000000',
        'is_active' => true,
    ], $overrides));
}

it('redirects a guest from /members to login', function () {
    $this->get('/members')->assertRedirect(route('login'));
});

it('lets an owner view the members index (Inertia Admin/Members/Index)', function () {
    $this->actingAs(memberAdminUser(UserRole::Owner))
        ->get('/members')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->component('Admin/Members/Index'));
});

it('lets a staff user view the members index (owner+staff surface, not 403)', function () {
    $this->actingAs(memberAdminUser(UserRole::Staff))
        ->get('/members')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->component('Admin/Members/Index'));
});

it('lets an owner view a member detail page (Inertia Admin/Members/Show)', function () {
    $member = memberAdminMember();

    $this->actingAs(memberAdminUser(UserRole::Owner))
        ->get(route('members.show', $member))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->component('Admin/Members/Show'));
});

it('lets a staff user view a member detail page (owner+staff surface)', function () {
    $member = memberAdminMember();

    $this->actingAs(memberAdminUser(UserRole::Staff))
        ->get(route('members.show', $member))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->component('Admin/Members/Show'));
});

it('filters the index by name via ?q= (name LIKE)', function () {
    $alice = memberAdminMember(['name' => 'Alice Wonderland', 'phone' => '0811111111']);
    $bob = memberAdminMember(['name' => 'Bob Builder', 'phone' => '0822222222']);

    $this->actingAs(memberAdminUser(UserRole::Owner))
        ->get('/members?q=Alice')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Admin/Members/Index')
            ->where('filters.q', 'Alice')
            ->has('members.data', 1)
            ->where('members.data.0.id', $alice->id));

    // sanity: Bob is not in the filtered result
    expect($bob->id)->not->toBe($alice->id);
});

it('filters the index by phone via ?q= (phone LIKE)', function () {
    memberAdminMember(['name' => 'Alice Wonderland', 'phone' => '0811111111']);
    $bob = memberAdminMember(['name' => 'Bob Builder', 'phone' => '0822222222']);

    $this->actingAs(memberAdminUser(UserRole::Owner))
        ->get('/members?q=08222')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Admin/Members/Index')
            ->has('members.data', 1)
            ->where('members.data.0.id', $bob->id));
});

it('creates a member via POST /members', function () {
    $this->actingAs(memberAdminUser(UserRole::Owner))
        ->post('/members', [
            'name' => 'New Counter Member',
            'phone' => '0844444444',
            'email' => 'counter@example.com',
            'is_active' => true,
        ])
        ->assertRedirect(route('members.index'));

    $this->assertDatabaseHas('members', [
        'name' => 'New Counter Member',
        'phone' => '0844444444',
        'email' => 'counter@example.com',
        'is_active' => true,
    ]);
});

it('blocks line_user_id injection on store (not in the request whitelist)', function () {
    $this->actingAs(memberAdminUser(UserRole::Owner))
        ->post('/members', [
            'name' => 'No LINE Yet',
            'phone' => '0855555555',
            'is_active' => true,
            // Attempt to set the LINE link directly — StoreMemberRequest does not
            // accept it, so the created member stays unlinked (§3.3).
            'line_user_id' => 'U_injected_line_id',
        ])
        ->assertRedirect(route('members.index'));

    $member = Member::firstWhere('name', 'No LINE Yet');
    expect($member)->not->toBeNull();
    expect($member->line_user_id)->toBeNull();
});

it('toggles is_active to false via PUT /members/{member} (unchecked = deactivate)', function () {
    $member = memberAdminMember(['name' => 'Toggle Me', 'is_active' => true]);

    $this->actingAs(memberAdminUser(UserRole::Owner))
        ->put(route('members.update', $member), [
            'name' => 'Toggle Me',
            'phone' => $member->phone,
            // is_active intentionally omitted → UpdateMemberRequest defaults it false.
        ])
        ->assertRedirect(route('members.show', $member));

    $this->assertDatabaseHas('members', ['id' => $member->id, 'is_active' => false]);
});

it('toggles is_active back to true via PUT /members/{member}', function () {
    $member = memberAdminMember(['name' => 'Reactivate Me', 'is_active' => false]);

    $this->actingAs(memberAdminUser(UserRole::Owner))
        ->put(route('members.update', $member), [
            'name' => 'Reactivate Me',
            'phone' => $member->phone,
            'is_active' => true,
        ])
        ->assertRedirect(route('members.show', $member));

    $this->assertDatabaseHas('members', ['id' => $member->id, 'is_active' => true]);
});

it('exposes a balanceByType aggregate on show summing qty_remaining per item', function () {
    $owner = memberAdminUser(UserRole::Owner);
    $member = memberAdminMember();

    // Sell a package (service qty 10, addon qty 3) via the service so the ledger +
    // entitlements are minted exactly as production does.
    $package = Package::create([
        'name' => 'Balance Package',
        'price' => '1290.00',
        'valid_days' => 30,
        'branch_id' => null,
        'is_active' => true,
    ]);
    $package->lines()->createMany([
        ['item_code' => 'SVC1', 'item_name' => 'Massage 60', 'item_type' => ItemType::Service, 'qty' => 10],
        ['item_code' => 'ADD1', 'item_name' => 'Hot stone', 'item_type' => ItemType::Addon, 'qty' => 3],
    ]);
    app(PurchaseService::class)->purchase($member, $package, '1290.00', $owner);

    $this->actingAs($owner)
        ->get(route('members.show', $member))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Admin/Members/Show')
            ->has('balanceByType', 2)
            // remainingByType orders by item_name: "Hot stone" (3) before "Massage 60" (10).
            ->where('balanceByType.0.item_code', 'ADD1')
            ->where('balanceByType.0.remaining', 3)
            ->where('balanceByType.1.item_code', 'SVC1')
            ->where('balanceByType.1.remaining', 10));
});

it('sums qty_remaining across multiple lots of the same item in balanceByType', function () {
    $owner = memberAdminUser(UserRole::Owner);
    $member = memberAdminMember();

    // Two sales of a single-line package (SVC1 qty 10 each) → balance sums to 20.
    $package = Package::create([
        'name' => 'Single Line',
        'price' => '500.00',
        'valid_days' => 30,
        'branch_id' => null,
        'is_active' => true,
    ]);
    $package->lines()->create([
        'item_code' => 'SVC1', 'item_name' => 'Massage 60', 'item_type' => ItemType::Service, 'qty' => 10,
    ]);

    app(PurchaseService::class)->purchase($member, $package, '500.00', $owner);
    app(PurchaseService::class)->purchase($member, $package, '500.00', $owner);

    $this->actingAs($owner)
        ->get(route('members.show', $member))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Admin/Members/Show')
            ->has('balanceByType', 1)
            ->where('balanceByType.0.item_code', 'SVC1')
            ->where('balanceByType.0.remaining', 20));
});
