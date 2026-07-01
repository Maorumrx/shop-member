<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Exceptions\LineAuthException;
use App\Exceptions\LinkException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Member\SubmitLinkCodeRequest;
use App\Models\Member;
use App\Services\Line\LiffVerifyService;
use App\Services\Line\MemberLinkService;
use App\Services\Line\MemberNotifier;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * LINE LIFF login for the member (`members`) guard.
 *
 * The Vue LIFF page initialises the LIFF SDK with `services.line.liff_id`,
 * obtains an ID token client-side, and POSTs it here. We verify the token
 * server-side (never trusting the browser) and resolve the Member by their stable
 * LINE `sub`.
 *
 * Two outcomes on first login (docs/member-line-linking-design.md §4):
 *   - MATCHED (a member already has this `line_user_id`) → the EXISTING login logic
 *     runs verbatim (reject trashed/inactive, backfill empties, log in, regenerate,
 *     `{ ok: true }`).
 *   - UNMATCHED → we NO LONGER auto-create an empty row (that stranded packages, §1).
 *     Instead we stash the verified LINE identity in the SESSION under `pending_line`
 *     and return `{ ok: false, state: 'needs_link' }`. No member is logged in yet.
 *     The customer then either submits a staff-issued claim code (submitCode) or
 *     explicitly creates a fresh walk-in account (createNew).
 *
 * All of this stays on the SEPARATE `members` session guard — it never touches the
 * admin `web`/`users` guard.
 *
 * @see \App\Services\Line\LiffVerifyService
 * @see \App\Services\Line\MemberLinkService
 */
final class MemberLineLoginController extends Controller
{
    /**
     * The session key under which a verified-but-unlinked LINE identity waits while
     * the customer chooses to link (submit a code) or create a fresh account. The
     * session cookie rides the axios `withCredentials` follow-up calls, so the
     * verified `sub` is carried server-side and never re-trusted from the browser.
     */
    private const PENDING_KEY = 'pending_line';

    /**
     * Verify a LIFF ID token and either log the matched member in, or park an
     * unmatched-but-verified LINE identity in the session as `needs_link`.
     *
     * Response is JSON. MATCHED → `{ ok: true }`. UNMATCHED → 200
     * `{ ok: false, state: 'needs_link' }` (NOT logged in). LINE verify failure →
     * 422 `{ ok: false, message }`. Trashed/disabled matched member → 403.
     *
     * TODO(frontend): the Vue LIFF page owns the post-login redirect (Inertia visit
     * to `route('member.dashboard')` after a 200 with `ok: true`); on
     * `state: 'needs_link'` it renders the link-or-create choice screen (§1).
     */
    public function store(Request $request, LiffVerifyService $liff): JsonResponse
    {
        $validated = $request->validate([
            'id_token' => ['required', 'string'],
        ]);

        try {
            $profile = $liff->verify($validated['id_token']);
        } catch (LineAuthException $e) {
            // Clean 422 — the Vue page shows a generic "LINE sign-in failed".
            return response()->json([
                'ok' => false,
                'message' => 'LINE sign-in failed. Please try again.',
            ], 422);
        }

        // Resolve by the stable LINE id, INCLUDING soft-deleted rows, so a deleted
        // member never silently spawns a duplicate (the unique index on the
        // still-present line_user_id would otherwise 500 the insert).
        $member = Member::withTrashed()->firstWhere('line_user_id', $profile['line_user_id']);

        // ── UNMATCHED (NEW, §4.1): DO NOT auto-create. Stash the verified LINE
        // identity server-side and ask the customer to link or create. No member is
        // logged in during this pending state — the follow-up submit-code /
        // create-new endpoints read `pending_line` from the session cookie.
        if ($member === null) {
            $request->session()->put(self::PENDING_KEY, [
                'sub' => $profile['line_user_id'],
                'name' => $profile['name'],
                'picture' => $profile['picture'],
                // Stamp so the pending window can be TTL'd (§4.1) — a verified identity
                // must not sit claimable for the whole session lifetime.
                'at' => now()->timestamp,
            ]);

            return response()->json([
                'ok' => false,
                'state' => 'needs_link',
            ]);
        }

        // ── MATCHED (UNCHANGED): the existing login logic, verbatim. ──────────────

        // A soft-deleted member was deliberately removed — never auto-restore via login.
        if ($member->trashed()) {
            return response()->json([
                'ok' => false,
                'message' => 'This account is unavailable.',
            ], 403);
        }

        // is_active = false is the canonical "disable without delete" (§3.3/§5.4)
        // — a disabled member must not be able to re-authenticate via LINE.
        if (! $member->is_active) {
            return response()->json([
                'ok' => false,
                'message' => 'This account is disabled.',
            ], 403);
        }

        // Backfill name/avatar from LINE ONLY when ours are empty — never clobber an
        // admin-curated value (an admin may create + name a member before LINE linking).
        if (($member->name === null || $member->name === '') && $profile['name'] !== null) {
            $member->name = $profile['name'];
        }

        if (($member->avatar_url === null || $member->avatar_url === '') && $profile['picture'] !== null) {
            $member->avatar_url = $profile['picture'];
        }

        if ($member->isDirty()) {
            $member->save();
        }

        // remember: true is intentional — a loyalty member opening their card inside
        // LINE expects to stay signed in on their own device.
        Auth::guard('members')->login($member, remember: true);

        // Re-key the session id after privilege change (session fixation guard).
        $request->session()->regenerate();

        return response()->json(['ok' => true]);
    }

    /**
     * Link path (§4.2): the customer submits a staff-issued 6-digit claim code to
     * attach their (pending) LINE identity to their existing counter member.
     *
     * PUBLIC + throttled — reads the verified LINE identity from the `pending_line`
     * session (never from the client). Requires that pending state (else a clean
     * 419-style 422 asking the customer to sign in again). Delegates the security
     * to {@see MemberLinkService::claim()}; a {@see LinkException} becomes a 422
     * `{ ok: false, message }` whose message NEVER reveals which member the code
     * points at. On success: log the member in, regenerate the session (which also
     * drops `pending_line`), `{ ok: true }`.
     */
    public function submitCode(SubmitLinkCodeRequest $request, MemberLinkService $links, MemberNotifier $notifier): JsonResponse
    {
        $pending = $this->pendingLine($request);

        if ($pending === null) {
            // The verified-LINE window is gone or older than the TTL (session expired /
            // never set / stale). Ask the customer to re-open LINE and sign in again.
            return response()->json([
                'ok' => false,
                'message' => 'Your LINE session has expired. Please sign in again.',
            ], 422);
        }

        try {
            $member = $links->claim(
                $request->code(),
                $pending['sub'],
                $pending['name'] ?? null,
                $pending['picture'] ?? null,
            );
        } catch (LinkException $e) {
            // Generic domain failure — invalid/expired/burned code, or the target
            // member became unclaimable. The exception message is already opaque
            // (never names the member); surface it verbatim. Nothing was linked.
            return response()->json([
                'ok' => false,
                'message' => $e->getMessage(),
            ], 422);
        }

        // Success: attach the session to the now-linked member.
        Auth::guard('members')->login($member, remember: true);

        // Regenerate re-keys the session (fixation guard) AND drops the now-spent
        // `pending_line` key with the old session.
        $request->session()->regenerate();

        // Best-effort LINE confirmation that the link succeeded — queued, never
        // blocks or fails the login (no-op if the member somehow lacks a line id).
        $notifier->linked($member);

        return response()->json(['ok' => true]);
    }

    /**
     * Create path (§4.2): the customer chose "I'm new / no code". This is the ONLY
     * remaining auto-create — a fresh LINE-linked walk-in member, minted on the
     * customer's EXPLICIT choice (never silently on first login).
     *
     * PUBLIC + throttled — requires the `pending_line` session (else a clean 422).
     * Creates the member from the verified LINE snapshot, logs in, regenerates.
     */
    public function createNew(Request $request, MemberNotifier $notifier): JsonResponse
    {
        $pending = $this->pendingLine($request);

        if ($pending === null) {
            return response()->json([
                'ok' => false,
                'message' => 'Your LINE session has expired. Please sign in again.',
            ], 422);
        }

        // createOrFirst() is race-safe: a double-submit resolves to the winning row
        // instead of a duplicate-key 500 on the line_user_id unique (I12). Defensive
        // — the pending window is single-customer, but the LIFF page could double-fire.
        $member = Member::createOrFirst(
            ['line_user_id' => $pending['sub']],
            [
                'name' => ($pending['name'] ?? null) ?: 'LINE Member',
                'avatar_url' => $pending['picture'] ?? null,
                'is_active' => true,
            ],
        );

        Auth::guard('members')->login($member, remember: true);

        // Regenerate re-keys the session and drops the spent `pending_line`.
        $request->session()->regenerate();

        // Best-effort welcome push — queued, never blocks or fails account
        // creation. A freshly LINE-linked walk-in always has a line_user_id.
        $notifier->welcome($member);

        return response()->json(['ok' => true]);
    }

    /**
     * How long a verified-but-unlinked LINE identity may sit in the session waiting
     * for the customer to link/create before it goes stale (§4.1): 10 minutes.
     */
    private const PENDING_TTL_SECONDS = 600;

    /**
     * The verified pending LINE identity from the session IFF it is present AND
     * within {@see self::PENDING_TTL_SECONDS}; otherwise null — and a stale entry is
     * forgotten. Keeps the "needs_link" window short so a parked, verified identity
     * can't be claimed against long after the login that created it.
     *
     * @return array{sub: string, name: string|null, picture: string|null}|null
     */
    private function pendingLine(Request $request): ?array
    {
        /** @var array{sub: string, name: string|null, picture: string|null, at?: int}|null $pending */
        $pending = $request->session()->get(self::PENDING_KEY);

        if (! is_array($pending) || ! isset($pending['sub'])) {
            return null;
        }

        // Enforce the TTL only when the login stamped `at` (it always does in prod);
        // a missing stamp — which an attacker can't forge into the session — is
        // treated as valid so it never blocks a legitimately-parked identity.
        if (isset($pending['at']) && (now()->timestamp - (int) $pending['at']) > self::PENDING_TTL_SECONDS) {
            $request->session()->forget(self::PENDING_KEY);

            return null;
        }

        return $pending;
    }

    /**
     * Log the member out of the `members` guard and tear down the session.
     */
    public function destroy(Request $request): RedirectResponse
    {
        Auth::guard('members')->logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect('/');
    }
}
