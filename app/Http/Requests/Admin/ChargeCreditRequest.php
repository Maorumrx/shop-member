<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use App\Models\Member;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Manually charge a member the ACTIVE catalog price of `item_code` — the
 * counter "use a service without a booking" path (the money-wallet reframe of the
 * dropped StoreRedemptionRequest). Authorization is the route middleware
 * (`role:owner,staff`), so authorize() returns true.
 *
 * Validates the request SHAPE only; the price lookup + sufficiency is a domain
 * concern resolved (and locked) inside
 * {@see \App\Services\Wallet\WalletService::chargeService()}, which throws a
 * {@see \App\Exceptions\WalletException} (unpriced item) or
 * {@see \App\Exceptions\InsufficientCreditException} (balance too low) the
 * controller turns into a clean 422.
 */
class ChargeCreditRequest extends FormRequest
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
            // The service code to charge. Existence/active/price is resolved by the
            // service, not by an exists rule (so a clean domain error, not a 500).
            'item_code' => ['required', 'string', 'max:40'],
        ];
    }

    /**
     * Reject charging an inactive / soft-deleted member (§3.3, §5.4).
     *
     * @param  \Illuminate\Validation\Validator  $validator
     */
    public function withValidator($validator): void
    {
        $validator->after(function ($validator): void {
            /** @var Member|null $member */
            $member = $this->route('member');

            if ($member !== null && ! $member->is_active) {
                $validator->errors()->add('member', __('สมาชิกถูกปิดใช้งาน หักเครดิตไม่ได้'));
            }
        });
    }
}
