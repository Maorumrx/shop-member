<?php

declare(strict_types=1);

namespace App\Services\Line;

use App\Exceptions\LinkException;
use App\Models\Member;
use App\Models\MemberLinkCode;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * MemberLinkService — mint + redeem the staff-generated LINE claim code that
 * attaches a customer's LINE account to their existing counter {@see Member}
 * (docs/member-line-linking-design.md §3, §4). This is the SECURITY core of the
 * linking flow: it is the ONLY place a `member_link_codes` row is minted or
 * consumed, and the ONLY place `members.line_user_id` is attached via a code.
 *
 * Two operations, each in ONE transaction:
 *   - generate(Member, User) — staff mint. Supersedes any live code for the member
 *     (under a member row lock) then inserts a fresh 6-digit code, returning the
 *     PLAINTEXT once. Only `hash('sha256', $code)` is persisted (§3).
 *   - claim(code, sub, name, picture) — customer redeem. Resolves the live code by
 *     hash, LOCKS the target member row (`lockForUpdate`), re-asserts the member is
 *     unlinked + active + not trashed (fail closed), attaches `line_user_id` (+avatar
 *     backfill), consumes the code, and returns the member. Wrong entries increment
 *     `attempts`; at 5 the code is burned.
 *
 * "One live code per member" is enforced HERE (service-level supersede under the
 * member lock), NOT by a DB unique — see the migration for the I20 decision. The
 * generate transaction serialises concurrent mints; the claim transaction's
 * member row lock serialises concurrent redemptions so exactly one LINE account
 * ever wins a given member (§5 concurrency).
 *
 * @see \App\Exceptions\LinkException
 * @see \App\Http\Controllers\Auth\MemberLineLoginController
 */
final class MemberLinkService
{
    /**
     * Per-code brute-force cap (§3). At this many recorded wrong entries the code
     * is burned (consumed) and can never validate again.
     */
    private const MAX_ATTEMPTS = 5;

    /**
     * Code lifetime — 24h from generation (§8, owner-tunable).
     */
    private const TTL_HOURS = 24;

    /**
     * Mint a fresh claim code for $member, performed by staff $staff.
     *
     * Rejects (LinkException) BEFORE writing anything if the member is already
     * LINE-linked, inactive, or soft-deleted — a code must never be minted for a
     * member that can't (or shouldn't) be claimed (§3). In ONE transaction, under a
     * `lockForUpdate` on the member row: supersedes any live code for this member
     * (`consumed_at = now()`), then inserts a new row with a random 6-digit code
     * (stored only as its SHA-256 hash), `expires_at = now()+24h`, and
     * `created_by_user_id = staff->id`.
     *
     * The PLAINTEXT code is returned ONCE (for staff to show the customer) and is
     * never persisted or recoverable afterwards (§3).
     *
     * @param  Member  $member  The unlinked, active, non-deleted counter member.
     * @param  User    $staff   The owner/staff generating the code (audit).
     * @return array{code: string, expires_at: string}  Plaintext 6-digit code +
     *         its expiry as a 'Y-m-d H:i:s' string (the model's datetime format).
     *
     * @throws LinkException When the member is already linked / inactive / trashed.
     */
    public function generate(Member $member, User $staff): array
    {
        // Early, friendly rejection (the admin button is hidden for linked members;
        // this is the race/defence backstop). Re-checked under the lock below.
        $this->assertClaimable($member);

        return DB::transaction(function () use ($member, $staff): array {
            // Lock the member row so a concurrent generate/claim serialises — the
            // "one live code per member" invariant lives here (no DB unique, per the
            // I20 migration decision).
            /** @var Member|null $locked */
            $locked = Member::query()
                ->whereKey($member->getKey())
                ->lockForUpdate()
                ->first();

            // Re-assert under the lock: a member linked/disabled/trashed between the
            // pre-check and the lock must not get a code (fail closed).
            if ($locked === null) {
                throw LinkException::memberUnavailable();
            }
            $this->assertClaimable($locked);

            // Supersede any still-live code for this member (regenerate replaces).
            // Uses I22 (member_id, consumed_at). expires_at is intentionally NOT in
            // the WHERE — an unexpired-but-live OR an already-expired-yet-unconsumed
            // row both become dead, leaving a clean single-live slate.
            MemberLinkCode::query()
                ->where('member_id', $locked->getKey())
                ->whereNull('consumed_at')
                ->update(['consumed_at' => now()]);

            // Fresh random 6-digit code. random_int is a CSPRNG (not mt_rand); the
            // full 000000..999999 space is used, zero-padded to a fixed 6 chars so a
            // leading-zero code (e.g. "004829") reads/prints correctly.
            $code = $this->randomSixDigits();

            $linkCode = MemberLinkCode::create([
                'member_id' => $locked->getKey(),
                'code_hash' => $this->hash($code),
                'expires_at' => now()->addHours(self::TTL_HOURS),
                'attempts' => 0,
                'consumed_at' => null,
                'consumed_by_line_user_id' => null,
                'created_by_user_id' => $staff->getKey(),
            ]);

            return [
                'code' => $code,
                // Model casts expires_at to a Carbon; toDateTimeString() yields the
                // stable 'Y-m-d H:i:s' the frontend can format for display.
                'expires_at' => $linkCode->expires_at->toDateTimeString(),
            ];
        });
    }

    /**
     * Redeem a claim code: attach $lineUserId to the code's target member.
     *
     * The security core. In ONE transaction:
     *   1. Resolve a LIVE code by hash (`consumed_at IS NULL AND expires_at > now()`,
     *      via I21). If none → increment `attempts` on any matching-hash row that
     *      still exists (so a persistent wrong-guess still burns down the cap) and
     *      throw invalidCode().
     *   2. `lockForUpdate` the candidate member row and re-assert it is
     *      `line_user_id IS NULL AND is_active AND NOT trashed` — fail closed.
     *   3. If the code already sits at the attempt cap → burn it (consume) + throw.
     *   4. On success: set `line_user_id`, backfill `avatar_url` from $picture ONLY
     *      when empty (never clobber; keep the admin-curated name), mark the code
     *      `consumed_at = now()` + `consumed_by_line_user_id = $lineUserId`, save.
     *
     * The member row lock serialises two devices submitting the SAME code: the
     * first commits (member linked, code consumed); the second re-reads a
     * now-linked member / consumed code and is rejected (§5 concurrency). Exactly
     * one LINE account wins.
     *
     * @param  string       $lineUserId  The verified LINE `sub` (from the LIFF token
     *                                    stashed server-side — never client-trusted).
     * @param  string|null  $name        LINE display name (used only if we ever need
     *                                    to fill an empty member name; admin name kept).
     * @param  string|null  $picture     LINE avatar URL (backfilled only if empty).
     * @return Member  The now-LINE-linked member, ready to log into the guard.
     *
     * @throws LinkException invalidCode / tooManyAttempts / memberNotClaimable —
     *         all generic (never reveal which member), transaction rolled back.
     */
    public function claim(string $code, string $lineUserId, ?string $name, ?string $picture): Member
    {
        $hash = $this->hash($code);

        // The transaction RETURNS an outcome instead of throwing mid-flight: a
        // rejection still has to COMMIT its penalise/burn write (throwing from inside
        // DB::transaction would roll that write back), so failure is surfaced as a
        // value and the exception is thrown only AFTER the transaction commits.
        /** @var array{member?: Member, error?: string} $outcome */
        $outcome = DB::transaction(function () use ($hash, $lineUserId, $name, $picture): array {
            // (1) Find a LIVE code by hash (I21). Lock it so a concurrent claim of
            // the SAME code can't both read it live.
            /** @var MemberLinkCode|null $live */
            $live = MemberLinkCode::query()
                ->where('code_hash', $hash)
                ->whereNull('consumed_at')
                ->where('expires_at', '>', now())
                ->lockForUpdate()
                ->first();

            if ($live === null) {
                // No live code. If a matching-hash row exists but is dead (expired /
                // consumed), record the failed attempt against it so a persistent
                // wrong-guess still counts down. This write must SURVIVE, so we return
                // the failure rather than throwing (which would roll it back).
                $this->penaliseDeadHash($hash);

                return ['error' => 'invalid'];
            }

            // (2) Attempt-cap guard, evaluated on the live row. At/over the cap →
            // burn (consume) and reject.
            if ($live->attempts >= self::MAX_ATTEMPTS) {
                $live->update(['consumed_at' => now()]);

                return ['error' => 'too_many'];
            }

            // (3) Lock the target member row and re-assert eligibility under the
            // lock — the whole point of the linking security (§3, §5). Include
            // trashed so a soft-deleted member is detected (and rejected), never
            // silently skipped by the default scope.
            /** @var Member|null $member */
            $member = Member::withTrashed()
                ->whereKey($live->member_id)
                ->lockForUpdate()
                ->first();

            if (
                $member === null
                || $member->trashed()
                || ! $member->is_active
                || $member->line_user_id !== null
            ) {
                // The member became linked/disabled/removed since the code was minted
                // (or, defensively, the code points at a gone member). Burn the code
                // so it can't be retried, and fail closed with a generic message.
                $live->update(['consumed_at' => now()]);

                return ['error' => 'not_claimable'];
            }

            // (4) SUCCESS. Attach LINE to the counter member. Backfill avatar ONLY
            // when ours is empty — never clobber an admin-curated value; the admin
            // NAME is always kept (we do not overwrite an existing name, and a
            // counter member always has one, so $name is effectively audit-only here).
            $member->line_user_id = $lineUserId;

            if (($member->avatar_url === null || $member->avatar_url === '') && $picture !== null && $picture !== '') {
                $member->avatar_url = $picture;
            }

            if (($member->name === null || $member->name === '') && $name !== null && $name !== '') {
                $member->name = $name;
            }

            $member->save();

            // Consume the code in the SAME transaction that attached line_user_id —
            // single-use is guaranteed the instant the link commits (§3).
            $live->update([
                'consumed_at' => now(),
                'consumed_by_line_user_id' => $lineUserId,
            ]);

            return ['member' => $member];
        });

        // Committed. A failure outcome now becomes the matching GENERIC exception
        // (messages never reveal which member or the exact reason); the penalise/burn
        // it performed is already persisted.
        if (isset($outcome['error'])) {
            throw match ($outcome['error']) {
                'too_many' => LinkException::tooManyAttempts(),
                'not_claimable' => LinkException::memberNotClaimable(),
                default => LinkException::invalidCode(),
            };
        }

        return $outcome['member'];
    }

    /**
     * Record a failed attempt against a DEAD matching-hash row, if one exists.
     *
     * Called when the submitted digits hash to a row that is no longer live
     * (expired or already consumed). We increment `attempts` on the newest such row
     * (bounded at the cap so the CHECK never trips) purely so a persistent brute
     * force still visibly counts — the code is already dead and can't validate. No
     * row (a hash that never existed) is a silent no-op; the caller still throws the
     * same generic invalidCode(), so an attacker learns nothing.
     */
    private function penaliseDeadHash(string $hash): void
    {
        /** @var MemberLinkCode|null $dead */
        $dead = MemberLinkCode::query()
            ->where('code_hash', $hash)
            ->orderByDesc('id')
            ->lockForUpdate()
            ->first();

        if ($dead !== null && $dead->attempts < self::MAX_ATTEMPTS) {
            $dead->increment('attempts');
        }
    }

    /**
     * Assert a member is eligible to have a code minted / claimed: unlinked, active,
     * and not soft-deleted (§3). Throws the appropriate LinkException otherwise.
     *
     * NOTE: an already-linked member raises alreadyLinked() (staff-facing), while an
     * inactive/trashed member raises memberUnavailable() (staff-facing). claim()
     * does its OWN eligibility re-check under the lock with generic messages so it
     * never leaks which member; this helper is used on the generate (staff) path
     * and as generate()'s pre-check.
     *
     * @throws LinkException
     */
    private function assertClaimable(Member $member): void
    {
        if ($member->trashed() || ! $member->is_active) {
            throw LinkException::memberUnavailable();
        }

        if ($member->line_user_id !== null) {
            throw LinkException::alreadyLinked();
        }
    }

    /**
     * A cryptographically-secure random 6-digit code as a zero-padded string
     * (e.g. "004829"). random_int() is a CSPRNG; the full 1,000,000 space is used.
     */
    private function randomSixDigits(): string
    {
        return str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
    }

    /**
     * The at-rest representation of a code: unsalted SHA-256 hex (§2 note — fast,
     * deterministic equality lookup by hash; the online-guess threat is covered by
     * the 5-attempt cap + per-caller rate limit, not by a slow hash).
     */
    private function hash(string $code): string
    {
        return hash('sha256', $code);
    }
}
