<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Exceptions\PurchaseException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StorePurchaseRequest;
use App\Models\Member;
use App\Models\Package;
use App\Services\Purchase\PurchaseService;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;

/**
 * Admin sale of a package to a member (architecture.md §3.6–§3.8) — the Phase-4
 * "sell" action. Owner AND staff (front-desk operators) — gated at the route via
 * `role:owner,staff`.
 *
 * The controller stays THIN: the request validates saleability (active package,
 * active member, decimal price with a list-price default) and the
 * {@see PurchaseService} performs the atomic mint (lot + per-line entitlement
 * snapshots + one purchase ledger row each). The acting staff user is recorded
 * as the ledger's `staff_id` for audit.
 */
class PurchaseController extends Controller
{
    /**
     * Sell `package_id` to the route {member} for `price_paid` (defaulted to the
     * package list price when omitted, in the request). Atomic via the service;
     * a domain PurchaseException (inactive / no-lines race) is surfaced as a clean
     * error toast rather than a 500.
     */
    public function store(StorePurchaseRequest $request, Member $member, PurchaseService $purchases): RedirectResponse
    {
        $data = $request->validated();

        /** @var Package $package */
        $package = Package::query()->findOrFail($data['package_id']);

        try {
            $purchases->purchase(
                member: $member,
                package: $package,
                // Validated decimal(10,2) string (list-price default applied) — never a float (§5.6).
                pricePaid: (string) $data['price_paid'],
                // The acting front-desk user → entitlement_ledger.staff_id.
                staff: $request->user(),
            );
        } catch (PurchaseException) {
            // Race: the package was deactivated/emptied between validation and the
            // transaction. Nothing was written (the whole txn rolled back).
            Inertia::flash('toast', ['type' => 'error', 'message' => __('ขายแพ็คเกจไม่ได้: แพ็คเกจไม่พร้อมขาย')]);

            return back();
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => __('ขายแพ็คเกจแล้ว')]);

        // Back to the member detail so the operator sees the new lot + balance.
        return to_route('members.show', $member);
    }
}
