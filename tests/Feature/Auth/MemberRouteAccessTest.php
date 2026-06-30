<?php

declare(strict_types=1);

// Phase 2 — member route access: public LIFF entry vs `auth:members` dashboard,
// and the public lineLiffId share. Inertia component assertions don't need a JS build.

use App\Models\Member;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;

uses(RefreshDatabase::class);

it('serves the public member LIFF entry page', function () {
    $this->get('/member')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->component('Member/Login'));
});

it('redirects an unauthenticated visitor away from the member dashboard', function () {
    $this->get('/member/dashboard')->assertRedirect();
});

it('lets an authenticated member view the dashboard', function () {
    $member = Member::create([
        'name' => 'Test Member',
        'line_user_id' => 'U_route_test',
        'is_active' => true,
    ]);

    $this->actingAs($member, 'members')
        ->get('/member/dashboard')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->component('Member/Dashboard'));
});

it('shares the public lineLiffId with the frontend (never the secret)', function () {
    config()->set('services.line.liff_id', '2010xxxxxx-test');

    $this->get('/member')
        ->assertInertia(fn (AssertableInertia $page) => $page->where('lineLiffId', '2010xxxxxx-test'));
});
