<?php

declare(strict_types=1);

// Phase 2 auth — Member LINE LIFF login (POST /member/line/login).
// Drives the real MemberLineLoginController end-to-end on the `members` guard,
// with LINE's verify endpoint ALWAYS Http::fake'd — never hit the network.
// See App\Http\Controllers\Auth\MemberLineLoginController.

use App\Models\Member;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

const MEM_VERIFY_URL = 'https://api.line.me/oauth2/v2.1/verify';
const MEM_ISSUER = 'https://access.line.me';
const MEM_CHANNEL_ID = '1234567890';
const MEM_SUB = 'U2222222222222222222222222222222';

/**
 * Fake LINE's verify endpoint to return a 200 with a valid payload, allowing
 * per-test overrides of the decoded claims.
 *
 * @param  array<string, mixed>  $overrides
 */
function memFakeLineOk(array $overrides = []): void
{
    config()->set('services.line.login_channel_id', MEM_CHANNEL_ID);

    Http::fake([
        MEM_VERIFY_URL => Http::response(array_merge([
            'sub' => MEM_SUB,
            'name' => 'LINE Display Name',
            'picture' => 'https://line.example/avatar.jpg',
            'aud' => MEM_CHANNEL_ID,
            'iss' => MEM_ISSUER,
            'exp' => now()->addHour()->getTimestamp(),
        ], $overrides), 200),
    ]);
}

/**
 * Fake LINE's verify endpoint to reject the token (mirrors an expired/forged one).
 */
function memFakeLineRejects(): void
{
    config()->set('services.line.login_channel_id', MEM_CHANNEL_ID);

    Http::fake([
        MEM_VERIFY_URL => Http::response([
            'error' => 'invalid_request',
            'error_description' => 'Invalid IdToken.',
        ], 400),
    ]);
}

function memLoginWithLine(string $token = 'liff-id-token')
{
    return test()->postJson(route('member.line.login'), ['id_token' => $token]);
}

it('parks an unmatched first login as needs_link WITHOUT creating a member or logging in', function () {
    // Design change (member-line-linking §4.1): an UNMATCHED first login NO LONGER
    // auto-creates an empty row (that stranded counter packages). Instead it stashes
    // the verified LINE identity in the session (`pending_line`) and returns
    // { ok: false, state: 'needs_link' } — nobody is logged in yet.
    memFakeLineOk();

    $response = memLoginWithLine();

    $response->assertOk()->assertJson(['ok' => false, 'state' => 'needs_link']);

    // The verified LINE identity is parked in the session for the follow-up
    // submit-code / create-new endpoints to consume (asserted on the request session).
    // Check the identity fields only — the controller also stamps an `at` timestamp
    // (pending-window TTL, §4.1), so assert via a closure rather than an exact array.
    $response->assertSessionHas('pending_line', function (array $pending): bool {
        return $pending['sub'] === MEM_SUB
            && $pending['name'] === 'LINE Display Name'
            && $pending['picture'] === 'https://line.example/avatar.jpg';
    });

    // No member row is created for the unmatched sub, and no one is authenticated.
    $this->assertGuest('members');
    expect(Member::query()->count())->toBe(0);
    expect(Member::query()->where('line_user_id', MEM_SUB)->count())->toBe(0);
});

it('still creates no member on a repeated unmatched login (needs_link every time)', function () {
    // Re-opening the LIFF page (a second unmatched login) again parks needs_link and
    // still creates nothing — no duplicate, no accidental auto-create.
    memFakeLineOk();

    memLoginWithLine()->assertOk()->assertJson(['ok' => false, 'state' => 'needs_link']);
    memLoginWithLine()->assertOk()->assertJson(['ok' => false, 'state' => 'needs_link']);

    $this->assertGuest('members');
    expect(Member::query()->count())->toBe(0);
});

it('rejects an inactive member with 403 and leaves the guard a guest', function () {
    memFakeLineOk();

    Member::create([
        'line_user_id' => MEM_SUB,
        'name' => 'Disabled Member',
        'is_active' => false,
    ]);

    $response = memLoginWithLine();

    $response->assertForbidden()->assertJson(['ok' => false]);
    $this->assertGuest('members');
    // No duplicate created — still just the one disabled row.
    expect(Member::query()->where('line_user_id', MEM_SUB)->count())->toBe(1);
});

it('rejects a soft-deleted member as unavailable without restoring or duplicating', function () {
    memFakeLineOk();

    $member = Member::create([
        'line_user_id' => MEM_SUB,
        'name' => 'Deleted Member',
        'is_active' => true,
    ]);
    $member->delete();

    $response = memLoginWithLine();

    $response->assertForbidden();
    expect($response->json('message'))->toContain('unavailable');

    $this->assertGuest('members');

    // No new (live) row spawned and the trashed row is NOT auto-restored.
    expect(Member::query()->count())->toBe(0);
    expect(Member::withTrashed()->count())->toBe(1);
    expect(Member::withTrashed()->firstWhere('line_user_id', MEM_SUB)->trashed())->toBeTrue();
});

it('does not overwrite an admin-set name on login (only backfills empty fields)', function () {
    memFakeLineOk();

    // Admin pre-created and named this member, and it is ALREADY LINE-linked
    // (line_user_id === MEM_SUB) so this login takes the MATCHED path — the only
    // path that still logs in + backfills empties. Avatar left empty for backfill.
    Member::create([
        'line_user_id' => MEM_SUB,
        'name' => 'Admin Curated Name',
        'avatar_url' => null,
        'is_active' => true,
    ]);

    memLoginWithLine()->assertOk();

    $member = Member::query()->firstWhere('line_user_id', MEM_SUB);
    // Curated name preserved...
    expect($member->name)->toBe('Admin Curated Name');
    // ...but the empty avatar IS backfilled from LINE.
    expect($member->avatar_url)->toBe('https://line.example/avatar.jpg');
});

it('returns 422 and stays a guest when LINE rejects the token', function () {
    memFakeLineRejects();

    $response = memLoginWithLine('bad-token');

    $response->assertStatus(422)->assertJson(['ok' => false]);
    $this->assertGuest('members');
    expect(Member::query()->count())->toBe(0);
});

it('does not authenticate the admin web guard when a member logs in (guard isolation)', function () {
    memFakeLineOk();

    // Pre-link a member so this login takes the MATCHED path and actually logs in
    // on the members guard (an unmatched login only parks needs_link, no auth).
    Member::create([
        'line_user_id' => MEM_SUB,
        'name' => 'Linked Member',
        'is_active' => true,
    ]);

    memLoginWithLine()->assertOk()->assertJson(['ok' => true]);

    $this->assertAuthenticated('members');
    // A customer session must never satisfy the admin guard.
    $this->assertGuest('web');
});

it('throttles after 10 requests per minute (11th call is 429)', function () {
    memFakeLineRejects();

    // 10 allowed hits (throttle:10,1). These 422 on the rejected token but still count.
    foreach (range(1, 10) as $i) {
        memLoginWithLine('bad-token')->assertStatus(422);
    }

    memLoginWithLine('bad-token')->assertStatus(429);
});
