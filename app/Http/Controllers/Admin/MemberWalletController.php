<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Exceptions\InsufficientCreditException;
use App\Exceptions\WalletException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\AdjustCreditRequest;
use App\Http\Requests\Admin\ChargeCreditRequest;
use App\Http\Requests\Admin\RefundCreditRequest;
use App\Models\Member;
use App\Models\Service;
use App\Services\Line\MemberNotifier;
use App\Services\Wallet\WalletService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

/**
 * Admin manual wallet actions on a member — the money-wallet reframe of the dropped
 * RedemptionController. Owner AND staff for charge/refund (route `role:owner,staff`);
 * adjust is OWNER-ONLY (route `role:owner`).
 *
 * The controller stays THIN: each request validates the shape; the single money
 * authority {@see WalletService} performs the atomic, lock-protected mutation. A
 * domain failure — insufficient balance ({@see InsufficientCreditException}) or a
 * money-rule violation ({@see WalletException}, e.g. an unpriced service or a refund
 * beyond the paid balance) — is turned into a 422 with a clean field message
 * (surfaced by Inertia as a form error), NEVER a 500. Nothing is written on failure
 * (the whole transaction rolled back).
 */
class MemberWalletController extends Controller
{
    /**
     * Manually charge the member the ACTIVE catalog price of `item_code` (a counter
     * "used a service without a booking" debit). On success flashes the wallet
     * result (touched lots + new balance) and a best-effort LINE receipt.
     */
    public function charge(ChargeCreditRequest $request, Member $member, WalletService $wallet, MemberNotifier $notifier): RedirectResponse
    {
        $staff = $request->user();
        $itemCode = (string) $request->validated('item_code');

        try {
            $result = $wallet->chargeService(
                member: $member,
                itemCode: $itemCode,
                staff: $staff,
                // Audit context only — the wallet is one fungible balance.
                branchId: $staff->branch_id,
            );
        } catch (InsufficientCreditException) {
            // Balance below the price — nothing debited (txn rolled back).
            throw ValidationException::withMessages([
                'item_code' => __('เครดิตไม่พอสำหรับบริการนี้'),
            ]);
        } catch (WalletException) {
            // No active price for the item.
            throw ValidationException::withMessages([
                'item_code' => __('ไม่พบราคาบริการนี้ (บริการยังไม่ได้ตั้งราคา)'),
            ]);
        }

        // Best-effort LINE receipt: service name + amount charged + remaining balance.
        // netDelta is negative for a debit; flip it to a positive baht string.
        $notifier->serviceChargeReceipt(
            $member,
            $this->serviceName($itemCode),
            bcsub('0', $result->netDelta, 2),
            $result->balanceAfter,
        );

        Inertia::flash('walletResult', $result->toArray());
        Inertia::flash('toast', ['type' => 'success', 'message' => __('หักเครดิตแล้ว')]);

        return to_route('members.show', $member);
    }

    /**
     * Refund PAID credit (never bonus) back out of the member's wallet, FIFO. A
     * refund beyond the refundable paid balance is a WalletException → 422.
     */
    public function refund(RefundCreditRequest $request, Member $member, WalletService $wallet): RedirectResponse
    {
        $staff = $request->user();

        try {
            $result = $wallet->refund(
                member: $member,
                amount: (string) $request->validated('amount'),
                staff: $staff,
                note: (string) $request->validated('note'),
            );
        } catch (WalletException) {
            // Requested more than the refundable (paid) balance — nothing written.
            throw ValidationException::withMessages([
                'amount' => __('คืนเงินได้ไม่เกินยอดเงินสดที่จ่ายมา (ไม่รวมโบนัส)'),
            ]);
        }

        Inertia::flash('walletResult', $result->toArray());
        Inertia::flash('toast', ['type' => 'success', 'message' => __('คืนเครดิตแล้ว')]);

        return to_route('members.show', $member);
    }

    /**
     * Owner correction — a SIGNED adjustment. A positive delta grants credit (held
     * as bonus in a new adjustment lot); a negative delta debits FIFO and is
     * rejected (422) if it would drive the balance below zero.
     */
    public function adjust(AdjustCreditRequest $request, Member $member, WalletService $wallet): RedirectResponse
    {
        $staff = $request->user();

        try {
            $result = $wallet->adjust(
                member: $member,
                delta: (string) $request->validated('delta'),
                staff: $staff,
                note: (string) $request->validated('note'),
            );
        } catch (InsufficientCreditException) {
            // A negative adjust would go below zero — nothing written.
            throw ValidationException::withMessages([
                'delta' => __('ปรับลดไม่ได้: เครดิตคงเหลือไม่พอ'),
            ]);
        } catch (WalletException) {
            // Defensive — the request already rejects a zero delta.
            throw ValidationException::withMessages([
                'delta' => __('ยอดปรับไม่ถูกต้อง'),
            ]);
        }

        Inertia::flash('walletResult', $result->toArray());
        Inertia::flash('toast', ['type' => 'success', 'message' => __('ปรับเครดิตแล้ว')]);

        return to_route('members.show', $member);
    }

    /**
     * Human label for a charged service code, for the LINE receipt. Falls back to
     * the code itself when the service can't be resolved (defensive — a successful
     * charge means it was priced).
     */
    private function serviceName(string $itemCode): string
    {
        return Service::query()
            ->where('item_code', $itemCode)
            ->value('name') ?? $itemCode;
    }
}
