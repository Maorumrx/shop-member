<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use App\Models\Member;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Redeem (ตัดสิทธิ์) `item_code × qty` for a member (architecture.md §6.3).
 * Authorization is the route middleware (`role:owner,staff`) — owner AND staff
 * are front-desk redeemers — so authorize() returns true.
 *
 * Validates the request SHAPE only; the entitlement balance / FIFO eligibility is
 * a domain concern resolved (and locked) inside {@see \App\Services\Redemption\RedemptionService}:
 *   - `item_code` is a required non-empty string (the snapshot code to consume).
 *   - `qty` is a nullable positive integer; omitted ⇒ DEFAULTS to 1 (the common
 *     "redeem one" counter case) via prepareForValidation.
 *   - the target member (route {member}) must be active and not soft-deleted (§5.4) —
 *     mirrors StorePurchaseRequest: route-model binding 404s a trashed member, and
 *     withValidator rejects a deactivated one.
 *
 * Whether the member actually HOLDS enough redeemable units is NOT checked here —
 * the service throws a RedemptionException (rolling back) which the controller
 * turns into a "สิทธิ์ไม่พอ" error toast.
 */
class StoreRedemptionRequest extends FormRequest
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
            // The snapshot item code to consume. Existence/eligibility is resolved
            // (and locked FIFO) by the service, not by an exists rule — a member may
            // legitimately hold a code that no longer exists in the live catalog
            // (snapshots outlive catalog edits, §5.1).
            'item_code' => ['required', 'string', 'max:40'],
            // Nullable: backfilled to 1 in prepareForValidation. min:1 forbids a
            // zero/negative redeem; max is a sane upper bound (a single counter
            // redemption never deducts thousands at once).
            'qty' => ['nullable', 'integer', 'min:1', 'max:1000'],
        ];
    }

    /**
     * Reject redeeming for an inactive / soft-deleted member (mirrors
     * StorePurchaseRequest, §3.3, §5.4). Route-model binding already 404s a
     * hard-missing or trashed member; this catches the deactivated case.
     *
     * @param  \Illuminate\Validation\Validator  $validator
     */
    public function withValidator($validator): void
    {
        $validator->after(function ($validator): void {
            /** @var Member|null $member */
            $member = $this->route('member');

            if ($member !== null && ! $member->is_active) {
                $validator->errors()->add('member', __('สมาชิกถูกปิดใช้งาน ตัดสิทธิ์ไม่ได้'));
            }
        });
    }

    /**
     * Default `qty` to 1 when omitted/blank so the service always receives a
     * concrete positive integer (the "redeem one" common case).
     */
    protected function prepareForValidation(): void
    {
        if (! $this->filled('qty')) {
            $this->merge(['qty' => 1]);
        }
    }

    /**
     * The validated quantity as a concrete int (>= 1) for the controller/service.
     */
    public function quantity(): int
    {
        return (int) $this->validated('qty');
    }
}
