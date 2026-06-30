<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Enums\EntitlementStatus;
use App\Enums\LedgerReason;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreMemberRequest;
use App\Http\Requests\Admin\UpdateMemberRequest;
use App\Models\Entitlement;
use App\Models\EntitlementLedger;
use App\Models\Member;
use App\Models\Package;
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
     */
    public function show(Member $member): Response
    {
        // Lots newest-first, each with its entitlements (the snapshots + caches).
        $member->load([
            'memberPackages' => fn ($q) => $q->orderByDesc('id'),
            'memberPackages.entitlements',
        ]);

        return Inertia::render('Admin/Members/Show', [
            'member' => $member,
            'balanceByType' => $this->remainingByType($member->id),
            // Active packages the Show page can sell (id, name, price). Price is
            // the decimal:2 string default for the price_paid field on the form.
            'activePackages' => Package::query()
                ->where('is_active', true)
                ->orderBy('name')
                ->get(['id', 'name', 'price']),
            // Recent consumption history (redeem/expire/refund), newest first.
            'history' => $this->redemptionHistory($member->id),
        ]);
    }

    /**
     * Recent entitlement movements for the member's activity feed (§6.4, I6
     * `(member_id, created_at)`): consumption-relevant reasons (redeem, expire,
     * refund), newest first, capped at 50. Eager-loads `staff` (who performed it)
     * and `entitlement:id,item_name` (the label) so the list renders with NO query
     * per row. The Phase-5 frontend renders this as the redemption history panel.
     *
     * @return list<array{
     *     id: int,
     *     created_at: string|null,
     *     item_name: string|null,
     *     reason: string,
     *     delta: int,
     *     balance_after: int,
     *     staff_name: string|null
     * }>
     */
    private function redemptionHistory(int $memberId): array
    {
        return EntitlementLedger::query()
            ->where('member_id', $memberId)
            ->whereIn('reason', [LedgerReason::Redeem, LedgerReason::Expire, LedgerReason::Refund])
            // N+1 guard: load the staff name + the entitlement label in two extra
            // queries total (not one per row), selecting only the columns rendered.
            ->with(['staff:id,name', 'entitlement:id,item_name'])
            ->orderByDesc('id')
            ->limit(50)
            ->get(['id', 'entitlement_id', 'member_id', 'delta', 'reason', 'balance_after', 'staff_id', 'created_at'])
            ->map(fn (EntitlementLedger $row): array => [
                'id' => $row->id,
                'created_at' => $row->created_at?->toIso8601String(),
                'item_name' => $row->entitlement?->item_name,
                'reason' => $row->reason->value,
                'delta' => (int) $row->delta,
                'balance_after' => (int) $row->balance_after,
                'staff_name' => $row->staff?->name,
            ])
            ->all();
    }

    /**
     * Aggregate live balance grouped by item (architecture.md §6.4 aggregate): a
     * single grouped SUM over ACTIVE, non-expired entitlements. `expires_at IS
     * NULL` (never-expires) counts; dated-but-not-yet-expired counts. Returned as
     * one row per distinct `item_code`/`item_name` so the Show page can render a
     * compact "remaining by type" summary.
     *
     * @return array<int, array{item_code: string, item_name: string, remaining: int}>
     */
    private function remainingByType(int $memberId): array
    {
        return Entitlement::query()
            ->where('member_id', $memberId)
            ->where('status', EntitlementStatus::Active)
            // Never-expiring (null) OR still in the future — leans on index I2.
            ->where(function ($w): void {
                $w->whereNull('expires_at')
                    ->orWhere('expires_at', '>', now());
            })
            ->groupBy('item_code', 'item_name')
            ->orderBy('item_name')
            ->selectRaw('item_code, item_name, SUM(qty_remaining) AS remaining')
            ->get()
            ->map(fn ($row): array => [
                'item_code' => (string) $row->item_code,
                'item_name' => (string) $row->item_name,
                'remaining' => (int) $row->remaining,
            ])
            ->all();
    }
}
