<?php

declare(strict_types=1);

namespace App\Http\Controllers\Member;

use App\Http\Controllers\Controller;
use App\Models\Member;
use App\Services\Member\MemberEntitlementQuery;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Member/Dashboard — the LINE-LIFF member home (Phase 6). This is the flagship
 * customer surface: it opens inside LINE on a phone, so the frontend is
 * mobile-first and rendered through the warm-soft MemberLayout (feed mode).
 *
 * Every number here comes from the shared {@see MemberEntitlementQuery} — the
 * SAME source of truth the admin detail page uses — so what the customer sees
 * matches the counter exactly. The member view calls `recentHistory` with
 * `includeStaff: false` so the feed NEVER leaks who performed a movement.
 *
 * Behind `auth:members` (routes/member.php); the authenticated member is read
 * from the `members` guard, NOT the default `web`/admin guard.
 */
class DashboardController extends Controller
{
    /**
     * Render the member dashboard for the authenticated member: their profile
     * greeting, the aggregate balance-by-type hero, their active lots (with
     * near-expiry flags), and the recent movement history (no staff names).
     */
    public function index(Request $request, MemberEntitlementQuery $entitlements): Response
    {
        /** @var Member $member */
        $member = $request->user('members');

        return Inertia::render('Member/Dashboard', [
            'member' => [
                'name' => $member->name,
                'avatar_url' => $member->avatar_url,
            ],
            'balanceByType' => $entitlements->remainingByType($member->id),
            'lots' => $entitlements->activeLots($member->id),
            // Member view — OMIT staff names (includeStaff: false).
            'history' => $entitlements->recentHistory($member->id, includeStaff: false),
        ]);
    }
}
