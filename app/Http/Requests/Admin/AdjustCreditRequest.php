<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use App\Models\Member;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Owner correction to a member's wallet — a SIGNED manual adjustment. OWNER-ONLY:
 * gated at the route via `role:owner` (adjust is the highest-trust wallet action),
 * so authorize() returns true (the middleware is the gate).
 *
 * `delta` is a SIGNED decimal-2 STRING (e.g. "500.00" grant, "-50.00" claw-back).
 * A positive delta mints an adjustment lot (value held as bonus); a negative delta
 * debits FIFO and is rejected by
 * {@see \App\Services\Wallet\WalletService::adjust()} (throwing an
 * {@see \App\Exceptions\InsufficientCreditException}) if it would drive the balance
 * below zero. A mandatory `note` records why.
 */
class AdjustCreditRequest extends FormRequest
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
            // SIGNED baht. numeric allows the leading '-'; decimal:0,2 keeps it a
            // clean 2-dp string; the between range keeps it inside decimal(10,2).
            // Non-zero is enforced in withValidator (the service also rejects zero).
            'delta' => ['required', 'numeric', 'decimal:0,2', 'between:-99999999.99,99999999.99'],
            'note' => ['required', 'string', 'max:255'],
        ];
    }

    /**
     * Reject adjusting an inactive / soft-deleted member (§3.3, §5.4) and a zero
     * (no-op) delta.
     *
     * @param  \Illuminate\Validation\Validator  $validator
     */
    public function withValidator($validator): void
    {
        $validator->after(function ($validator): void {
            /** @var Member|null $member */
            $member = $this->route('member');

            if ($member !== null && ! $member->is_active) {
                $validator->errors()->add('member', __('สมาชิกถูกปิดใช้งาน ปรับเครดิตไม่ได้'));
            }

            $delta = (string) ($this->input('delta') ?? '0');
            if (bccomp($delta, '0', 2) === 0) {
                $validator->errors()->add('delta', __('ต้องระบุยอดปรับที่ไม่เท่ากับ 0'));
            }
        });
    }
}
