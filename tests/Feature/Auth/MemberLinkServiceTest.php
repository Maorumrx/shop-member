<?php

declare(strict_types=1);

// Phase 8 auth — MemberLinkService (the SECURITY core of member ↔ LINE linking,
// docs/member-line-linking-design.md §3–§5). Drives the service DIRECTLY (no HTTP)
// to prove the mint + redeem contract of the staff-issued 6-digit claim code:
//   - generate(Member, User) — mint. Returns a 6-digit plaintext + future expiry,
//     stores ONLY a SHA-256 hash (never plaintext), supersedes any prior live code,
//     and rejects an already-linked / inactive / trashed member (fail closed).
//   - claim(code, sub, name, picture) — redeem. A live code attaches line_user_id
//     (avatar backfilled if empty, admin name kept) and consumes the code; a wrong
//     code increments `attempts` and burns the row at 5; an expired / consumed code
//     or a member that became linked/inactive/trashed is rejected (fail closed).
//
// A LinkException is thrown on every rejection (opaque messages on the claim path).
// See App\Services\Line\MemberLinkService + App\Exceptions\LinkException.
//
// MariaDB-only caveat: the `attempts` CHECK (0..5) is skipped on the sqlite test DB
// (guarded in the migration), so the "burn at 5" path is exercised here on sqlite
// without tripping a constraint; the service is the real cap regardless of driver.

use App\Exceptions\LinkException;
use App\Models\Member;
use App\Models\MemberLinkCode;
use App\Models\User;
use App\Enums\UserRole;
use App\Services\Line\MemberLinkService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

const LINK_SVC_SUB = 'U9999999999999999999999999999999';

/** The MemberLinkService under test, resolved from the container. */
function linkService(): MemberLinkService
{
    return app(MemberLinkService::class);
}

/** Active, verified staff operator — the audit `created_by_user_id` on a minted code. */
function linkSvcStaff(UserRole $role = UserRole::Staff): User
{
    return User::factory()->create([
        'role' => $role,
        'is_active' => true,
        'email_verified_at' => now(),
    ]);
}

/** A plain unlinked, active counter member (the claim target). */
function linkSvcMember(array $overrides = []): Member
{
    return Member::create(array_merge([
        'name' => 'Counter Member',
        'phone' => '0812223333',
        'is_active' => true,
    ], $overrides));
}

/** SHA-256 hex of a code — the at-rest representation the service stores. */
function linkSvcHash(string $code): string
{
    return hash('sha256', $code);
}

// ── generate() ────────────────────────────────────────────────────────────────

it('generate returns a 6-digit code with a future expiry and stores only its hash', function () {
    $member = linkSvcMember();
    $staff = linkSvcStaff();

    $result = linkService()->generate($member, $staff);

    // Shape: exactly 6 ASCII digits (leading zeros preserved), future expiry string.
    expect($result['code'])->toMatch('/^\d{6}$/');
    expect($result['expires_at'])->toBeString();
    expect(now()->lt($result['expires_at']))->toBeTrue();

    // Exactly one code row, keyed to this member + staff, still LIVE (unconsumed).
    $row = MemberLinkCode::query()->sole();
    expect($row->member_id)->toBe($member->id);
    expect($row->created_by_user_id)->toBe($staff->id);
    expect($row->consumed_at)->toBeNull();
    expect($row->attempts)->toBe(0);

    // The PLAINTEXT is never persisted — only its SHA-256 hash is stored.
    expect($row->code_hash)->toBe(linkSvcHash($result['code']));
    expect(MemberLinkCode::query()->where('code_hash', $result['code'])->exists())->toBeFalse();
});

it('generate supersedes a prior live code for the same member (old one consumed)', function () {
    $member = linkSvcMember();
    $staff = linkSvcStaff();

    $first = linkService()->generate($member, $staff);
    $firstRow = MemberLinkCode::query()->where('code_hash', linkSvcHash($first['code']))->sole();

    // Regenerate — the second mint must supersede the first (single live code, §6).
    $second = linkService()->generate($member, $staff);

    // The FIRST code is now dead (consumed_at set); the SECOND is the only live one.
    expect($firstRow->fresh()->consumed_at)->not->toBeNull();

    $live = MemberLinkCode::query()
        ->where('member_id', $member->id)
        ->whereNull('consumed_at')
        ->get();
    expect($live)->toHaveCount(1);
    expect($live->first()->code_hash)->toBe(linkSvcHash($second['code']));
});

it('generate rejects an already LINE-linked member', function () {
    $member = linkSvcMember(['line_user_id' => LINK_SVC_SUB]);
    $staff = linkSvcStaff();

    expect(fn () => linkService()->generate($member, $staff))
        ->toThrow(LinkException::class);

    // Nothing minted — a linked member must never get a code.
    expect(MemberLinkCode::query()->count())->toBe(0);
});

it('generate rejects an inactive member', function () {
    $member = linkSvcMember(['is_active' => false]);
    $staff = linkSvcStaff();

    expect(fn () => linkService()->generate($member, $staff))
        ->toThrow(LinkException::class);

    expect(MemberLinkCode::query()->count())->toBe(0);
});

it('generate rejects a soft-deleted member', function () {
    $member = linkSvcMember();
    $member->delete();
    $staff = linkSvcStaff();

    expect(fn () => linkService()->generate($member, $staff))
        ->toThrow(LinkException::class);

    expect(MemberLinkCode::query()->count())->toBe(0);
});

// ── claim() happy path ──────────────────────────────────────────────────────────

it('claim attaches the line_user_id, backfills an empty avatar, keeps the name, and consumes the code', function () {
    // avatar_url empty (backfilled), name set by admin (must be kept).
    $member = linkSvcMember(['name' => 'Admin Curated Name', 'avatar_url' => null]);
    $staff = linkSvcStaff();

    $code = linkService()->generate($member, $staff)['code'];

    $claimed = linkService()->claim($code, LINK_SVC_SUB, 'LINE Display Name', 'https://line.example/pic.jpg');

    // Returned member is the linked one, with LINE attached.
    expect($claimed->id)->toBe($member->id);
    expect($claimed->line_user_id)->toBe(LINK_SVC_SUB);

    $fresh = $member->fresh();
    expect($fresh->line_user_id)->toBe(LINK_SVC_SUB);
    // Empty avatar backfilled from LINE...
    expect($fresh->avatar_url)->toBe('https://line.example/pic.jpg');
    // ...but the admin-curated NAME is never clobbered.
    expect($fresh->name)->toBe('Admin Curated Name');

    // The code is consumed in the same transaction, recording who claimed it.
    $row = MemberLinkCode::query()->where('code_hash', linkSvcHash($code))->sole();
    expect($row->consumed_at)->not->toBeNull();
    expect($row->consumed_by_line_user_id)->toBe(LINK_SVC_SUB);
});

it('claim does not backfill an avatar the admin already set', function () {
    $member = linkSvcMember(['avatar_url' => 'https://admin.example/curated.jpg']);
    $staff = linkSvcStaff();

    $code = linkService()->generate($member, $staff)['code'];

    linkService()->claim($code, LINK_SVC_SUB, 'LINE Display Name', 'https://line.example/pic.jpg');

    // The existing avatar is preserved — LINE never clobbers a curated value.
    expect($member->fresh()->avatar_url)->toBe('https://admin.example/curated.jpg');
});

// ── claim() wrong code + attempt cap ───────────────────────────────────────────

it('claim increments attempts on a persistently re-typed dead code (penaliseDeadHash)', function () {
    // The service records a failed attempt against a matching-hash row that is DEAD
    // (expired/consumed) so a persistent wrong-guess still counts down. Reproduce it:
    // mint a code, kill it (consume), then re-submit that SAME code — each submit is
    // invalidCode() and increments `attempts` on the dead row.
    $member = linkSvcMember();
    $staff = linkSvcStaff();

    $code = linkService()->generate($member, $staff)['code'];
    $row = MemberLinkCode::query()->sole();

    // Kill the code so it is no longer live (a wrong guess that hashes to a dead row).
    $row->update(['consumed_at' => now()]);
    expect($row->fresh()->attempts)->toBe(0);

    // First re-submit → invalidCode + attempts 0→1.
    expect(fn () => linkService()->claim($code, LINK_SVC_SUB, null, null))
        ->toThrow(LinkException::class);
    expect($row->fresh()->attempts)->toBe(1);

    // Second re-submit → attempts 1→2. Member never links off a dead code.
    expect(fn () => linkService()->claim($code, LINK_SVC_SUB, null, null))
        ->toThrow(LinkException::class);
    expect($row->fresh()->attempts)->toBe(2);
    expect($member->fresh()->line_user_id)->toBeNull();
});

it('claim burns the code at the attempt cap, so even the correct code then fails', function () {
    $member = linkSvcMember();
    $staff = linkSvcStaff();

    $correct = linkService()->generate($member, $staff)['code'];
    $row = MemberLinkCode::query()->sole();

    // Drive the live code to the brute-force cap (5 prior wrong entries recorded on
    // this row). The next claim — even with the CORRECT digits — must burn + reject.
    // (sqlite: no CHECK on `attempts`, so setting 5 is fine; MariaDB caps 0..5 too.)
    $row->update(['attempts' => 5]);

    expect(fn () => linkService()->claim($correct, LINK_SVC_SUB, null, null))
        ->toThrow(LinkException::class);

    // The code is now consumed (burned) and the member remains unlinked.
    expect($row->fresh()->consumed_at)->not->toBeNull();
    expect($member->fresh()->line_user_id)->toBeNull();

    // A retry of the correct code still fails — the row is dead for good.
    expect(fn () => linkService()->claim($correct, LINK_SVC_SUB, null, null))
        ->toThrow(LinkException::class);
    expect($member->fresh()->line_user_id)->toBeNull();
});

// ── claim() fail-closed rejections ──────────────────────────────────────────────

it('claim rejects an expired code and never links the member', function () {
    $member = linkSvcMember();
    $staff = linkSvcStaff();

    $code = linkService()->generate($member, $staff)['code'];

    // Force the code past its window (24h TTL) so it is no longer live.
    MemberLinkCode::query()->where('code_hash', linkSvcHash($code))
        ->update(['expires_at' => now()->subMinute()]);

    expect(fn () => linkService()->claim($code, LINK_SVC_SUB, null, null))
        ->toThrow(LinkException::class);

    expect($member->fresh()->line_user_id)->toBeNull();
});

it('claim rejects an already-consumed code', function () {
    $member = linkSvcMember();
    $staff = linkSvcStaff();

    $code = linkService()->generate($member, $staff)['code'];

    // Mark the code consumed (as a successful claim / supersede would).
    MemberLinkCode::query()->where('code_hash', linkSvcHash($code))
        ->update(['consumed_at' => now()]);

    expect(fn () => linkService()->claim($code, LINK_SVC_SUB, null, null))
        ->toThrow(LinkException::class);

    expect($member->fresh()->line_user_id)->toBeNull();
});

it('claim fails closed when the member got LINE-linked in the meantime', function () {
    $member = linkSvcMember();
    $staff = linkSvcStaff();

    $code = linkService()->generate($member, $staff)['code'];

    // The member became linked to ANOTHER LINE account after the code was minted.
    $member->update(['line_user_id' => 'Uother_line_account_xxxxxxxxxxxxxxx']);

    expect(fn () => linkService()->claim($code, LINK_SVC_SUB, null, null))
        ->toThrow(LinkException::class);

    // Not overwritten — the pre-existing link wins, and the code is burned.
    expect($member->fresh()->line_user_id)->toBe('Uother_line_account_xxxxxxxxxxxxxxx');
    expect(MemberLinkCode::query()->where('code_hash', linkSvcHash($code))->sole()->consumed_at)->not->toBeNull();
});

it('claim fails closed when the member became inactive in the meantime', function () {
    $member = linkSvcMember();
    $staff = linkSvcStaff();

    $code = linkService()->generate($member, $staff)['code'];

    $member->update(['is_active' => false]);

    expect(fn () => linkService()->claim($code, LINK_SVC_SUB, null, null))
        ->toThrow(LinkException::class);

    expect($member->fresh()->line_user_id)->toBeNull();
});

it('claim fails closed when the member was soft-deleted in the meantime', function () {
    $member = linkSvcMember();
    $staff = linkSvcStaff();

    $code = linkService()->generate($member, $staff)['code'];

    $member->delete();

    expect(fn () => linkService()->claim($code, LINK_SVC_SUB, null, null))
        ->toThrow(LinkException::class);

    // The trashed member is NOT linked/restored.
    expect(Member::withTrashed()->find($member->id)->line_user_id)->toBeNull();
});
