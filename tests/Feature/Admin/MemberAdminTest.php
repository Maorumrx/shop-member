<?php

declare(strict_types=1);

// Member admin (MemberController + Store/UpdateMemberRequest, §3.3). Routes live behind
// ['auth','verified','role:owner,staff'] in routes/admin.php (no uri prefix), so the
// members surface is OWNER AND STAFF (staff are front-desk operators — NOT 403 here).
//
// Contracts under test:
//   - access gate: a guest is redirected to login; both owner AND staff reach
//     GET /members (Admin/Members/Index) and GET /members/{member} (Admin/Members/Show).
//   - index ?q= searches name OR phone (LIKE) and returns only matching rows.
//   - store creates a member but `line_user_id` is NOT mass-assignable (whitelist).
//   - update toggles is_active (unchecked box → deactivate).
//   - show exposes the money-wallet projections: a single `balance` string, active
//     `lots`, and the `history` feed (with staff_name for admin), plus the sell inputs
//     (`topupOffers`, `services`) — the money-wallet reframe of the dropped balanceByType.
//
// Wallet state is seeded ONLY via WalletService::topUp so the numbers are honest. Flash
// is Inertia::flash('toast', ...); Inertia prop assertions need no JS build.

use App\Enums\CreditSource;
use App\Enums\UserRole;
use App\Models\Member;
use App\Models\Service;
use App\Models\TopupOffer;
use App\Models\User;
use App\Services\Wallet\WalletService;
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

it('exposes the wallet balance, active lots, and staff-aware history on show', function () {
    $owner = memberAdminUser(UserRole::Owner);
    $member = memberAdminMember();

    // Seed a top-up (paid 1000 + bonus 200) via the money authority, acting staff = owner.
    app(WalletService::class)->topUp($member, '1000.00', '200.00', CreditSource::Topup, $owner);

    $this->actingAs($owner)
        ->get(route('members.show', $member))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Admin/Members/Show')
            // ONE spendable-balance figure (decimal-2 string), not a per-type map.
            ->where('balance', '1200.00')
            // Exactly one active lot, with its total remaining.
            ->has('lots', 1)
            ->where('lots.0.amount_paid', '1000.00')
            ->where('lots.0.bonus_amount', '200.00')
            ->where('lots.0.total_remaining', '1200.00')
            // History newest-first: bonus row (id higher) then topup row.
            ->has('history', 2)
            ->where('history.0.reason', 'bonus')
            ->where('history.0.delta', '200.00')
            ->where('history.1.reason', 'topup')
            // Admin history keeps WHO performed the movement.
            ->where('history.1.staff_name', $owner->name)
            ->etc());
});

it('exposes the top-up presets and priced services for the sell/charge inputs on show', function () {
    $owner = memberAdminUser(UserRole::Owner);
    $member = memberAdminMember();

    TopupOffer::create(['name' => 'Preset', 'amount' => '5000.00', 'bonus' => '500.00', 'is_active' => true, 'sort_order' => 1]);
    Service::create(['item_code' => 'MASSAGE_60', 'name' => 'Thai Massage 60', 'price' => '300.00', 'branch_id' => null, 'is_active' => true]);

    $this->actingAs($owner)
        ->get(route('members.show', $member))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Admin/Members/Show')
            ->has('topupOffers', 1)
            ->where('topupOffers.0.amount', '5000.00')
            ->has('services', 1)
            ->where('services.0.item_code', 'MASSAGE_60')
            ->etc());
});
