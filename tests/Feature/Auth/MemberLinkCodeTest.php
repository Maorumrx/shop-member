<?php

declare(strict_types=1);

// Phase 8 auth — member ↔ LINE claim-code ENDPOINTS (the "needs_link" follow-ups,
// docs/member-line-linking-design.md §4.2 + the admin generate surface). Drives the
// real controllers end-to-end. The `pending_line` session (a verified-but-unlinked
// LINE identity) is established the way production does: Http::fake LINE's verify,
// then POST an UNMATCHED sub to member.line.login, which parks pending_line and
// returns needs_link. The follow-up submit-code / create-new calls then read it.
//
// Contracts under test:
//   - submit-code: no pending_line → clean 422 (no crash); pending_line + correct
//     code → { ok: true }, logged into `members`, line_user_id attached; wrong code
//     → 422 opaque, still a guest.
//   - create-new: pending_line → fresh line-linked member + logged in; none → 422.
//   - generate endpoint (members.link-code, role:owner,staff): owner AND staff can
//     (302 + linkCode flashed + a row minted); guest → login redirect; a members-guard
//     session cannot reach it (tolerate 302|403); already-linked member → error flash,
//     no new code.
//
// LINE verify is ALWAYS Http::fake'd (mirrors MemberLineLoginTest) — never the network.
// MariaDB-only caveat: the `attempts` CHECK (0..5) is skipped on the sqlite test DB.
// See MemberLineLoginController + MemberController@generateLinkCode + MemberLinkService.

use App\Enums\UserRole;
use App\Models\Member;
use App\Models\MemberLinkCode;
use App\Models\User;
use App\Services\Line\MemberLinkService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

const CODE_VERIFY_URL = 'https://api.line.me/oauth2/v2.1/verify';
const CODE_ISSUER = 'https://access.line.me';
const CODE_CHANNEL_ID = '1234567890';
const CODE_SUB = 'U3333333333333333333333333333333';

/**
 * Fake LINE's verify endpoint (200 + valid payload), mirroring MemberLineLoginTest.
 *
 * @param  array<string, mixed>  $overrides
 */
function codeFakeLineOk(array $overrides = []): void
{
    config()->set('services.line.login_channel_id', CODE_CHANNEL_ID);

    Http::fake([
        CODE_VERIFY_URL => Http::response(array_merge([
            'sub' => CODE_SUB,
            'name' => 'LINE Display Name',
            'picture' => 'https://line.example/avatar.jpg',
            'aud' => CODE_CHANNEL_ID,
            'iss' => CODE_ISSUER,
            'exp' => now()->addHour()->getTimestamp(),
        ], $overrides), 200),
    ]);
}

/**
 * Establish the `pending_line` session END-TO-END: an UNMATCHED login parks the
 * verified LINE identity and returns needs_link (no member created, nobody logged in).
 * The session cookie then rides the follow-up submit-code / create-new call.
 */
function codeParkPendingLine(): void
{
    codeFakeLineOk();

    test()->postJson(route('member.line.login'), ['id_token' => 'liff-id-token'])
        ->assertOk()
        ->assertJson(['ok' => false, 'state' => 'needs_link']);
}

/** Active, verified admin operator (owner or staff) — the members.link-code surface. */
function codeAdmin(UserRole $role): User
{
    return User::factory()->create([
        'role' => $role,
        'is_active' => true,
        'email_verified_at' => now(),
    ]);
}

/** A plain unlinked, active counter member (the claim target). */
function codeMember(array $overrides = []): Member
{
    return Member::create(array_merge([
        'name' => 'Counter Member',
        'phone' => '0812224444',
        'is_active' => true,
    ], $overrides));
}

// ── submit-code ─────────────────────────────────────────────────────────────────

it('submit-code without a pending_line session returns a clean 422 and does not crash', function () {
    // No prior login → no pending_line. A well-formed code still 422s cleanly asking
    // the customer to sign in again (NOT a 500).
    $response = test()->postJson(route('member.line.submit-code'), ['code' => '123456']);

    $response->assertStatus(422)->assertJson(['ok' => false]);
    test()->assertGuest('members');
});

it('submit-code with pending_line + the correct code links the member and logs in', function () {
    // Mint a code for an unlinked counter member via the service (as staff would).
    $member = codeMember(['name' => 'Admin Curated Name', 'avatar_url' => null]);
    $staff = codeAdmin(UserRole::Staff);
    $code = app(MemberLinkService::class)->generate($member, $staff)['code'];

    // Park the verified LINE identity, then submit the code from the SAME session.
    codeParkPendingLine();

    $response = test()->postJson(route('member.line.submit-code'), ['code' => $code]);

    $response->assertOk()->assertJson(['ok' => true]);
    test()->assertAuthenticated('members');

    // The counter member now carries the LINE sub; avatar backfilled, name kept.
    $fresh = $member->fresh();
    expect($fresh->line_user_id)->toBe(CODE_SUB);
    expect($fresh->avatar_url)->toBe('https://line.example/avatar.jpg');
    expect($fresh->name)->toBe('Admin Curated Name');

    // No accidental extra member row (the pending identity linked the existing one).
    expect(Member::query()->count())->toBe(1);
});

it('submit-code with a wrong code returns an opaque 422 and stays a guest', function () {
    $member = codeMember();
    $staff = codeAdmin(UserRole::Staff);
    // Mint a real code, but the customer submits DIFFERENT digits.
    app(MemberLinkService::class)->generate($member, $staff);

    codeParkPendingLine();

    $response = test()->postJson(route('member.line.submit-code'), ['code' => '000000']);

    $response->assertStatus(422)->assertJson(['ok' => false]);
    // Opaque message — never reveals the member / why it failed.
    expect($response->json('message'))->toBeString();

    test()->assertGuest('members');
    expect($member->fresh()->line_user_id)->toBeNull();
});

it('submit-code validates the code shape (non-6-digit is a 422)', function () {
    codeParkPendingLine();

    // SubmitLinkCodeRequest requires exactly 6 digits.
    test()->postJson(route('member.line.submit-code'), ['code' => 'abc'])
        ->assertStatus(422);
    test()->assertGuest('members');
});

// ── create-new ──────────────────────────────────────────────────────────────────

it('create-new with pending_line creates a fresh line-linked member and logs in', function () {
    codeParkPendingLine();

    $response = test()->postJson(route('member.line.create-new'));

    $response->assertOk()->assertJson(['ok' => true]);
    test()->assertAuthenticated('members');

    // Exactly one fresh member, linked to the pending LINE identity + LINE snapshot.
    $member = Member::query()->sole();
    expect($member->line_user_id)->toBe(CODE_SUB);
    expect($member->name)->toBe('LINE Display Name');
    expect($member->avatar_url)->toBe('https://line.example/avatar.jpg');
    expect($member->is_active)->toBeTrue();
});

it('create-new without a pending_line session returns a clean 422 and creates nothing', function () {
    $response = test()->postJson(route('member.line.create-new'));

    $response->assertStatus(422)->assertJson(['ok' => false]);
    test()->assertGuest('members');
    expect(Member::query()->count())->toBe(0);
});

// ── generate endpoint (admin members.link-code) ─────────────────────────────────

it('lets an owner generate a link code (302 + linkCode flashed + a row minted)', function () {
    $member = codeMember();

    // Inertia::flash writes to the nested `inertia.flash_data` key (not a top-level
    // session key), so success is proved by redirect + the minted DB row — the same
    // convention every admin test uses. assertInertiaFlash covers the flash surface.
    test()->actingAs(codeAdmin(UserRole::Owner))
        ->post(route('members.link-code', $member))
        ->assertRedirect(route('members.show', $member))
        ->assertInertiaFlash('linkCode');

    // Exactly one live code minted for this member.
    $row = MemberLinkCode::query()->where('member_id', $member->id)->sole();
    expect($row->consumed_at)->toBeNull();
    expect($row->code_hash)->toHaveLength(64);
});

it('lets a staff user generate a link code (owner+staff surface, not 403)', function () {
    $member = codeMember();

    test()->actingAs(codeAdmin(UserRole::Staff))
        ->post(route('members.link-code', $member))
        ->assertRedirect(route('members.show', $member))
        ->assertInertiaFlash('linkCode');

    expect(MemberLinkCode::query()->where('member_id', $member->id)->count())->toBe(1);
});

it('redirects a guest from the generate endpoint to login and mints nothing', function () {
    $member = codeMember();

    test()->post(route('members.link-code', $member))
        ->assertRedirect(route('login'));

    expect(MemberLinkCode::query()->count())->toBe(0);
});

it('does not let a members-guard session reach the generate endpoint', function () {
    $member = codeMember();

    // A members-guard session must NOT mint an admin code. As in MemberToggleTest,
    // actingAs($member, 'members') makes `members` the default guard so the admin
    // role gate 403s (a real web-guard-less request would 302 to login). Either way
    // the request is blocked and nothing is minted; tolerate 302|403.
    $response = test()->actingAs($member, 'members')
        ->post(route('members.link-code', $member));

    expect(in_array($response->status(), [302, 403], true))->toBeTrue();
    expect(MemberLinkCode::query()->count())->toBe(0);
});

it('flashes an error and mints no code when the member is already LINE-linked', function () {
    // Already linked → the service rejects; the controller surfaces a toast, not a 500,
    // and no code is minted.
    $member = codeMember(['line_user_id' => 'Ualready_linked_xxxxxxxxxxxxxxxxxxx']);

    test()->actingAs(codeAdmin(UserRole::Owner))
        ->post(route('members.link-code', $member))
        ->assertRedirect()
        ->assertInertiaFlashMissing('linkCode');

    expect(MemberLinkCode::query()->where('member_id', $member->id)->count())->toBe(0);
});

it('returns 404 when generating a code for a soft-deleted member (route binding)', function () {
    // Member uses SoftDeletes — implicit route binding excludes trashed rows, so the
    // router 404s before the controller (mirrors MemberToggleTest).
    $member = codeMember();
    $member->delete();

    test()->actingAs(codeAdmin(UserRole::Owner))
        ->post(route('members.link-code', $member))
        ->assertNotFound();

    expect(MemberLinkCode::query()->count())->toBe(0);
});
