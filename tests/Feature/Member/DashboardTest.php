<?php

declare(strict_types=1);

// Member dashboard: GET /member/dashboard (member.dashboard) → DashboardController@index,
// behind `auth:members`. Renders the Inertia component Member/Dashboard with props sourced
// from the shared MemberWalletQuery (the SAME source of truth the admin detail page uses),
// but with `includeStaff: false` so the member feed NEVER leaks who performed a movement.
//
// Contracts under test:
//   - an authenticated member gets 200 + the Member/Dashboard component;
//   - a guest is redirected away;
//   - DATA ISOLATION: acting as member A, the props reflect ONLY A's balance/lots/history
//     and contain NONE of member B's figures;
//   - the member `history` rows OMIT `staff_name`, even though the underlying ledger row
//     carries a staff_id;
//   - `lots` lists ACTIVE lots only (used_up excluded), each with `is_near_expiry` true
//     for a dated lot inside the 30-day window and false for a far-future one;
//   - `balance` is the single decimal-2 spendable figure.
//
// Wallet state is seeded ONLY via WalletService::topUp / debit so the numbers are honest.
// Inertia prop assertions need no JS build.

use App\Enums\CreditLedgerReason;
use App\Enums\CreditSource;
use App\Enums\UserRole;
use App\Models\Member;
use App\Models\User;
use App\Services\Wallet\WalletService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;

uses(RefreshDatabase::class);

/** A plain active member (the `members` guard identity under test). */
function dashboardMember(array $overrides = []): Member
{
    return Member::create(array_merge([
        'name' => 'Dashboard Member',
        'phone' => '0840000000',
        'is_active' => true,
    ], $overrides));
}

/** A real active staff user (credit_ledger.staff_id FKs to users.id). */
function dashboardStaff(): User
{
    return User::factory()->create([
        'role' => UserRole::Staff,
        'is_active' => true,
        'email_verified_at' => now(),
    ]);
}

it('lets an authenticated member view the dashboard (Inertia Member/Dashboard)', function () {
    $member = dashboardMember();

    $this->actingAs($member, 'members')
        ->get(route('member.dashboard'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Member/Dashboard')
            ->where('member.name', 'Dashboard Member'));
});

it('redirects an unauthenticated visitor away from the member dashboard', function () {
    $this->get(route('member.dashboard'))->assertRedirect();
});

it('exposes ONLY the acting member wallet — no cross-member leak', function () {
    $memberA = dashboardMember(['name' => 'Member A', 'phone' => '0811111111']);
    $memberB = dashboardMember(['name' => 'Member B', 'phone' => '0822222222']);

    app(WalletService::class)->topUp($memberA, '1000.00', '0.00', CreditSource::Topup, null);
    // B's distinctive amount must never surface in A's props.
    app(WalletService::class)->topUp($memberB, '9999.00', '0.00', CreditSource::Topup, null);

    $this->actingAs($memberA, 'members')
        ->get(route('member.dashboard'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Member/Dashboard')
            ->where('balance', '1000.00')
            ->has('lots', 1)
            ->where('lots.0.amount_paid', '1000.00'));

    // Belt-and-suspenders: B's figure appears nowhere in A's serialized props.
    $response = $this->actingAs($memberA, 'members')->get(route('member.dashboard'));
    expect(json_encode($response->viewData('page')['props']))->not->toContain('9999');
});

it('omits staff_name from the member history feed (never leaks who acted)', function () {
    $member = dashboardMember();
    $staff = dashboardStaff();

    // A REAL staff performs the top-up, so the ledger row genuinely HAS a staff_id.
    app(WalletService::class)->topUp($member, '500.00', '0.00', CreditSource::Topup, $staff);

    $this->actingAs($member, 'members')
        ->get(route('member.dashboard'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Member/Dashboard')
            ->has('history', 1)
            ->where('history.0.reason', CreditLedgerReason::Topup->value)
            ->where('history.0.delta', '500.00')
            // The member view omits the staff column entirely (includeStaff: false).
            ->missing('history.0.staff_name'));

    $response = $this->actingAs($member, 'members')->get(route('member.dashboard'));
    expect(json_encode($response->viewData('page')['props']))->not->toContain('staff_name');
});

it('lists ACTIVE lots only and flags near-expiry correctly', function () {
    $member = dashboardMember();
    $wallet = app(WalletService::class);

    // A lot fully spent → used_up, must be EXCLUDED from `lots`. Drain it first,
    // before the surviving lots exist, so the debit hits only this lot.
    $wallet->topUp($member, '300.00', '0.00', CreditSource::Topup, null);
    $wallet->debit($member, '300.00', CreditLedgerReason::Debit, null);

    // A dated lot expiring in ~10 days → near-expiry (inside the default 30-day window).
    $near = $wallet->topUp($member, '500.00', '0.00', CreditSource::Topup, null, null, now()->addDays(10));
    // A dated lot expiring far in the future → NOT near-expiry.
    $later = $wallet->topUp($member, '500.00', '0.00', CreditSource::Topup, null, null, now()->addDays(120));

    $this->actingAs($member, 'members')
        ->get(route('member.dashboard'))
        ->assertOk()
        ->assertInertia(function (AssertableInertia $page) use ($near, $later) {
            $page->component('Member/Dashboard')
                // Only the two ACTIVE lots — the used_up lot is excluded.
                ->has('lots', 2);

            $lots = collect($page->toArray()['props']['lots'])->keyBy('id');

            expect($lots[$near->id]['is_near_expiry'])->toBeTrue();
            expect($lots[$later->id]['is_near_expiry'])->toBeFalse();
        });
});

it('exposes the single spendable balance figure as a decimal-2 string', function () {
    $member = dashboardMember();
    app(WalletService::class)->topUp($member, '1000.00', '200.00', CreditSource::Topup, null);

    $this->actingAs($member, 'members')
        ->get(route('member.dashboard'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Member/Dashboard')
            ->where('balance', '1200.00'));
});
