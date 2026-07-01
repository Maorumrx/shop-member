<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreMemberRequest;
use App\Http\Requests\Admin\UpdateMemberRequest;
use App\Models\Member;
use App\Models\Package;
use App\Services\Member\MemberEntitlementQuery;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Admin member management (architecture.md §3.3). Owner AND staff (front-desk
 * operators) — gated at the route via `role:owner,staff`. This is the "who do we
 * sell to" surface: list/search members, create counter accounts, edit basics,
 * and view a member's owned lots + live balance before selling (Phase 4).
 *
 * Members are NEVER hard-deleted (SoftDeletes, §5.4) — there is no destroy here;
 * deactivate via `is_active=false` (the update form).
 */
class MemberController extends Controller
{
    /**
     * Paginated member list (20/page), newest first, with an optional `?q=`
     * search across name OR phone (LIKE).
     *
     * N+1 guard: select only the columns the table renders; the LINE-linked
     * badge is derived from `line_user_id` so no relation load is needed (§6.4).
     * `withQueryString()` keeps `?q=` across pagination links.
     */
    public function index(): Response
    {
        $q = trim((string) request('q', ''));

        $members = Member::query()
            ->when($q !== '', function ($query) use ($q): void {
                // Name OR phone LIKE — the counter looks people up by either.
                // `phone` is indexed (I11); a leading-wildcard LIKE won't use the
                // index but the member table is small, so this is acceptable.
                $query->where(function ($w) use ($q): void {
                    $w->where('name', 'like', "%{$q}%")
                        ->orWhere('phone', 'like', "%{$q}%");
                });
            })
            ->orderByDesc('id')
            ->paginate(20)
            // Keep ?q= on the pagination links, then trim each row to what the
            // table renders + a derived `is_line_linked` flag for the badge.
            ->withQueryString()
            ->through(fn (Member $m): array => [
                'id' => $m->id,
                'name' => $m->name,
                'phone' => $m->phone,
                'is_active' => $m->is_active,
                'is_line_linked' => $m->line_user_id !== null,
            ]);

        return Inertia::render('Admin/Members/Index', [
            'members' => $members,
            'filters' => ['q' => $q],
        ]);
    }

    /**
     * Admin-create a counter member. `line_user_id` stays null (links to LINE
     * later, §3.3); the request whitelists name/phone/email/is_active only.
     */
    public function store(StoreMemberRequest $request): RedirectResponse
    {
        Member::create($request->validated());

        Inertia::flash('toast', ['type' => 'success', 'message' => __('เพิ่มสมาชิกแล้ว')]);

        return to_route('members.index');
    }

    /**
     * Edit a member's name/phone/email/is_active (§3.3). Deactivation (is_active
     * false) is the supported "disable" path — members are never hard-deleted.
     */
    public function update(UpdateMemberRequest $request, Member $member): RedirectResponse
    {
        $member->update($request->validated());

        Inertia::flash('toast', ['type' => 'success', 'message' => __('อัปเดตสมาชิกแล้ว')]);

        return to_route('members.show', $member);
    }

    /**
     * Flip `is_active` (deactivate/reactivate without hard-delete, §3.3, §5.4).
     * Kept separate from update so the index can toggle inline with a single
     * PATCH.
     */
    public function toggle(Member $member): RedirectResponse
    {
        $member->update(['is_active' => ! $member->is_active]);

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => $member->is_active ? __('เปิดใช้งานสมาชิกแล้ว') : __('ปิดใช้งานสมาชิกแล้ว'),
        ]);

        return back();
    }

    /**
     * Member detail: owned lots + their entitlements, an aggregate "remaining by
     * type" balance summary, the active-package list so the page can sell, and the
     * recent redemption/movement history so the operator sees what was deducted.
     *
     * N+1 guard: eager-load `memberPackages.entitlements` in ONE pass (§6.4) so
     * the lots table renders every item without a query per lot. The balance
     * summary is a SINGLE grouped query (NOT per-row hydration of the loaded
     * collection) per the §6.4 aggregate guidance. The history feed eager-loads
     * `staff` + `entitlement:id,item_name` so each row renders without a query per
     * row (§6.4).
     *
     * The balance + history projections come from the shared
     * {@see MemberEntitlementQuery} (the SINGLE source of truth also used by the
     * member dashboard, Phase 6). Admin passes `includeStaff: true` so the history
     * keeps its `staff_name` column.
     */
    public function show(Member $member, MemberEntitlementQuery $entitlements): Response
    {
        // Lots newest-first, each with its entitlements (the snapshots + caches).
        $member->load([
            'memberPackages' => fn ($q) => $q->orderByDesc('id'),
            'memberPackages.entitlements',
        ]);

        return Inertia::render('Admin/Members/Show', [
            'member' => $member,
            'balanceByType' => $entitlements->remainingByType($member->id),
            // Active packages the Show page can sell (id, name, price). Price is
            // the decimal:2 string default for the price_paid field on the form.
            'activePackages' => Package::query()
                ->where('is_active', true)
                ->orderBy('name')
                ->get(['id', 'name', 'price']),
            // Recent consumption history (redeem/expire/refund), newest first.
            // Admin sees WHO performed each movement (staff_name).
            'history' => $entitlements->recentHistory($member->id, includeStaff: true),
        ]);
    }
}
