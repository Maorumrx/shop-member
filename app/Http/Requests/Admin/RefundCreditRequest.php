<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use App\Models\Member;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Refund PAID credit back out of a member's wallet. Authorization is the route
 * middleware (`role:owner,staff`), so authorize() returns true.
 *
 * A refund reverses PAID value only (never bonus); whether the member holds enough
 * refundable paid balance is a domain concern the
 * {@see \App\Services\Wallet\WalletService::refund()} guards (throwing a
 * {@see \App\Exceptions\WalletException} the controller turns into a 422). Money is
 * a decimal-2 STRING (§5.6); a `note` (reason) is mandatory for the audit trail.
 */
class RefundCreditRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            // Baht to refund; must be strictly positive (gt:0). Stored/handled as a
            // 2-dp string, never float (§5.6).
            'amount' => ['required', 'numeric', 'decimal:0,2', 'gt:0', 'max:99999999.99'],
            // The reason is stamped on every ledger row the refund writes.
            'note' => ['required', 'string', 'max:255'],
        ];
    }

    /**
     * Reject refunding an inactive / soft-deleted member (§3.3, §5.4).
     *
     * @param  \Illuminate\Validation\Validator  $validator
     */
    public function withValidator($validator): void
    {
        $validator->after(function ($validator): void {
            /** @var Member|null $member */
            $member = $this->route('member');

            if ($member !== null && ! $member->is_active) {
                $validator->errors()->add('member', __('สมาชิกถูกปิดใช้งาน คืนเครดิตไม่ได้'));
            }
        });
    }
}
