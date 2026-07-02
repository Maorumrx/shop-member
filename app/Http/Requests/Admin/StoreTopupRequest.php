<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use App\Models\Member;
use App\Models\TopupOffer;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Sell credit to a member — the money-wallet reframe of the dropped
 * StorePurchaseRequest. Authorization is the route middleware (`role:owner,staff`)
 * — owner AND staff are front-desk sellers — so authorize() returns true.
 *
 * A top-up is EITHER a preset (`topup_offer_id`) OR a custom pair
 * (`amount_paid` + `bonus_amount`). When a preset id is given the amounts are
 * resolved SERVER-SIDE from the offer row ({@see resolvedAmounts()}) — any client
 * amount is ignored, so a tampered price can never be honoured. Money is decimal-2
 * STRING throughout (§5.6); {@see \App\Services\Wallet\WalletService::topUp()}
 * re-guards non-negative / non-empty inside its transaction.
 */
class StoreTopupRequest extends FormRequest
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
            // A preset must reference an ACTIVE offer (hidden presets aren't sellable).
            'topup_offer_id' => [
                'nullable',
                'integer',
                Rule::exists('topup_offers', 'id')->where('is_active', true),
            ],
            // Custom path: required only when no preset was chosen. decimal:0,2 keeps
            // it a clean 2-dp string; min:0 forbids negatives (§5.6).
            'amount_paid' => ['required_without:topup_offer_id', 'nullable', 'numeric', 'decimal:0,2', 'min:0', 'max:99999999.99'],
            'bonus_amount' => ['nullable', 'numeric', 'decimal:0,2', 'min:0', 'max:99999999.99'],
        ];
    }

    /**
     * Reject selling to an inactive / soft-deleted member (mirrors the old sell
     * path, §3.3, §5.4), and require a positive total on the CUSTOM path (a preset
     * already guarantees amount > 0 via its own validation).
     *
     * @param  \Illuminate\Validation\Validator  $validator
     */
    public function withValidator($validator): void
    {
        $validator->after(function ($validator): void {
            /** @var Member|null $member */
            $member = $this->route('member');

            if ($member !== null && ! $member->is_active) {
                $validator->errors()->add('member', __('สมาชิกถูกปิดใช้งาน เติมเครดิตไม่ได้'));
            }

            // Custom path only: at least one of paid/bonus must be > 0.
            if (! $this->filled('topup_offer_id')) {
                $paid = (string) ($this->input('amount_paid') ?? '0');
                $bonus = (string) ($this->input('bonus_amount') ?? '0');

                if (bccomp($paid, '0', 2) !== 1 && bccomp($bonus, '0', 2) !== 1) {
                    $validator->errors()->add('amount_paid', __('ต้องระบุยอดเงินที่มากกว่า 0'));
                }
            }
        });
    }

    /**
     * Resolve the paid + bonus amounts to hand the WalletService, as decimal-2
     * STRINGS. When a preset id is present the amounts come from the OFFER row
     * (server-side authority — client amounts ignored); otherwise the validated
     * custom pair (each defaulting to "0" when omitted).
     *
     * @return array{paid: string, bonus: string}
     */
    public function resolvedAmounts(): array
    {
        $offerId = $this->validated('topup_offer_id');

        if ($offerId !== null) {
            /** @var TopupOffer $offer */
            $offer = TopupOffer::query()->findOrFail($offerId);

            // Cast the decimal:2 model attributes to plain strings — never float.
            return [
                'paid' => (string) $offer->amount,
                'bonus' => (string) $offer->bonus,
            ];
        }

        return [
            'paid' => (string) ($this->validated('amount_paid') ?? '0'),
            'bonus' => (string) ($this->validated('bonus_amount') ?? '0'),
        ];
    }
}
