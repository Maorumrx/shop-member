<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Exceptions\LineAuthException;
use App\Http\Controllers\Controller;
use App\Models\Member;
use App\Services\Line\LiffVerifyService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * LINE LIFF login for the member (`members`) guard.
 *
 * The Vue LIFF page initialises the LIFF SDK with `services.line.liff_id`,
 * obtains an ID token client-side, and POSTs it here. We verify the token
 * server-side (never trusting the browser), upsert the Member by their stable
 * LINE `sub`, and log them into the SEPARATE `members` session guard — this
 * never touches the admin `web`/`users` guard.
 *
 * @see \App\Services\Line\LiffVerifyService
 */
final class MemberLineLoginController extends Controller
{
    /**
     * Verify a LIFF ID token, upsert the member, and start a member session.
     *
     * Response is JSON `{ ok: true }` for now; the Vue LIFF page owns the
     * post-login redirect (e.g. to `route('member.dashboard')`).
     *
     * TODO(frontend): once the Member/Dashboard page exists, the Vue side
     * should redirect to it after a 200 here (Inertia visit), or this can be
     * switched to `redirect()->intended(route('member.dashboard'))`.
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

        // A soft-deleted member was deliberately removed — never auto-restore via login.
        if ($member?->trashed()) {
            return response()->json([
                'ok' => false,
                'message' => 'This account is unavailable.',
            ], 403);
        }

        // First login: createOrFirst() is race-safe — a concurrent double-submit
        // resolves to the winning row instead of a duplicate-key 500.
        $member ??= Member::createOrFirst(
            ['line_user_id' => $profile['line_user_id']],
            [
                'name' => $profile['name'] ?? 'LINE Member',
                'avatar_url' => $profile['picture'] ?? null,
                'is_active' => true,
            ],
        );

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
        if (! $member->wasRecentlyCreated) {
            if (($member->name === null || $member->name === '') && $profile['name'] !== null) {
                $member->name = $profile['name'];
            }

            if (($member->avatar_url === null || $member->avatar_url === '') && $profile['picture'] !== null) {
                $member->avatar_url = $profile['picture'];
            }

            if ($member->isDirty()) {
                $member->save();
            }
        }

        // remember: true is intentional — a loyalty member opening their card inside
        // LINE expects to stay signed in on their own device.
        Auth::guard('members')->login($member, remember: true);

        // Re-key the session id after privilege change (session fixation guard).
        $request->session()->regenerate();

        return response()->json(['ok' => true]);
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
