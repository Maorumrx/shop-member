<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Enums\CreditSource;
use App\Exceptions\WalletException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreTopupRequest;
use App\Models\Member;
use App\Services\Line\MemberNotifier;
use App\Services\Wallet\WalletService;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;

/**
 * Admin sell-credit action on a member — the money-wallet reframe of the dropped
 * PurchaseController. Owner AND staff (front-desk sellers) — gated at the route via
 * `role:owner,staff`.
 *
 * The controller stays THIN: {@see StoreTopupRequest} validates the shape and
 * resolves the paid/bonus amounts SERVER-SIDE (from a preset or the custom pair),
 * and {@see WalletService::topUp()} performs the atomic mint (one credit_lots row +
 * opening credit_ledger row(s)). The acting staff is recorded as the lot's creator
 * and the ledger's staff_id; the staff's home branch is snapshotted onto the lot.
 */
class TopupController extends Controller
{
    /**
     * Sell credit to the route {member}. Amounts come from the request's resolver
     * (a preset overrides any client amount). A WalletException (defensive — the
     * request already guards a positive total) is surfaced as a clean error toast
     * rather than a 500; nothing was written (the whole txn rolled back).
     */
    public function store(StoreTopupRequest $request, Member $member, WalletService $wallet, MemberNotifier $notifier): RedirectResponse
    {
        $staff = $request->user();
        $amounts = $request->resolvedAmounts();

        try {
            $wallet->topUp(
                member: $member,
                amountPaid: $amounts['paid'],
                bonusAmount: $amounts['bonus'],
                source: CreditSource::Topup,
                staff: $staff,
                // Snapshot the acting operator's home branch (owner = null = any-branch).
                branchId: $staff->branch_id,
                // Expiry capability stays OFF — every lot ships with expires_at null.
                expiresAt: null,
            );
        } catch (WalletException) {
            Inertia::flash('toast', ['type' => 'error', 'message' => __('เติมเครดิตไม่ได้: ยอดเงินไม่ถูกต้อง')]);

            return back();
        }

        // Best-effort LINE receipt AFTER the top-up committed — the member's new
        // balance is re-read from the money authority. Queued, never blocks/fails
        // the sale (no-op if the member isn't LINE-linked).
        $notifier->topupReceipt($member, $wallet->balance($member));

        Inertia::flash('toast', ['type' => 'success', 'message' => __('เติมเครดิตแล้ว')]);

        // Back to the member detail so the operator sees the new balance + lot.
        return to_route('members.show', $member);
    }
}
