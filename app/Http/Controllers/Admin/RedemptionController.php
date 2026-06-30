<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Exceptions\RedemptionException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreRedemptionRequest;
use App\Models\Member;
use App\Services\Redemption\RedemptionService;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;

/**
 * Admin redemption (ตัดสิทธิ์) of a member's entitlements — the Phase-5 REVENUE
 * core (architecture.md §6.3). Owner AND staff (front-desk operators) — gated at
 * the route via `role:owner,staff`.
 *
 * The controller stays THIN: the request validates the shape + member status and
 * the {@see RedemptionService} performs the atomic, lock-protected FIFO
 * consumption (decrement + one redeem ledger row per touched entitlement, coupled
 * redeem_group siblings, lot rollup). The acting staff user is recorded as the
 * ledger's `staff_id`.
 *
 * BRANCH CONTEXT (§5.5): redemption eligibility is scoped to the acting staff's
 * HOME branch (`users.branch_id`). An OWNER has `branch_id = null` → we pass null,
 * which the service treats as UNSCOPED (the owner may redeem any lot). v1 has no
 * separate branch picker; the operator's own branch is the context.
 */
class RedemptionController extends Controller
{
    /**
     * Redeem `item_code × qty` for the route {member}. Atomic via the service; a
     * domain RedemptionException (insufficient / nothing redeemable) is surfaced
     * as a clean "สิทธิ์ไม่พอ" error toast — nothing was deducted (the whole
     * transaction rolled back). On success the per-line breakdown is flashed so
     * the Show page can render exactly what was taken.
     */
    public function store(StoreRedemptionRequest $request, Member $member, RedemptionService $redemptions): RedirectResponse
    {
        $staff = $request->user();

        // Owner: branch_id is null → null = UNSCOPED (any lot). Staff: their home
        // branch scopes eligibility (§5.5). No separate branch picker in v1.
        $branchId = $staff->branch_id;

        try {
            $result = $redemptions->redeem(
                member: $member,
                itemCode: (string) $request->validated('item_code'),
                qty: $request->quantity(),
                staff: $staff,
                branchId: $branchId,
            );
        } catch (RedemptionException $e) {
            // Insufficient / no eligible lot — nothing was written (txn rolled back).
            Inertia::flash('toast', ['type' => 'error', 'message' => __('สิทธิ์ไม่พอ')]);

            return back();
        }

        // Stash the precise breakdown so the Show page can render exactly what was
        // deducted (e.g. "ตัดนวด 1 (จากล็อตหมด X) เหลือ Y; ประคบ 1"), beyond the toast.
        Inertia::flash('redemption', $result->toArray());
        Inertia::flash('toast', ['type' => 'success', 'message' => __('ตัดสิทธิ์แล้ว')]);

        // Back to the member detail so the operator sees the updated balance/history.
        return to_route('members.show', $member);
    }
}
