<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use App\Models\Member;
use App\Models\Package;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Sell a package to a member (architecture.md §3.6–§3.8). Authorization is
 * handled by the route middleware (`role:owner,staff`) — owner AND staff are
 * front-desk sellers — so authorize() returns true.
 *
 * Validates the SALEABILITY preconditions before the PurchaseService runs:
 *   - `package_id` exists AND the package `is_active` (no selling a hidden one).
 *   - the target member (route {member}) is active and not soft-deleted (§5.4).
 *   - `price_paid` is a decimal(10,2) ≥ 0; when omitted it DEFAULTS to the
 *     package list price (the common counter case), NEVER parsed as a float (§5.6).
 *
 * The PurchaseService re-guards package active/has-lines inside its transaction
 * (race-safe), throwing a PurchaseException the controller turns into an error
 * toast.
 */
class StorePurchaseRequest extends FormRequest
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
            'package_id' => [
                'required',
                'integer',
                // Must reference an ACTIVE package (hidden/soft-off packages are
                // not sellable, §3.4). The scoped exists rule does both checks.
                Rule::exists('packages', 'id')->where('is_active', true),
            ],
            // Nullable: when omitted we backfill the package price in
            // prepareForValidation (so the rule sees a concrete decimal string).
            // decimal:0,2 ⇒ at most 2 fractional digits; min:0 forbids negatives;
            // max keeps it inside decimal(10,2) (§5.6).
            'price_paid' => ['nullable', 'numeric', 'decimal:0,2', 'min:0', 'max:99999999.99'],
        ];
    }

    /**
     * Reject selling to an inactive / soft-deleted member, and default
     * `price_paid` to the chosen package's list price when the operator left it
     * blank. Runs after rules() so we can read the validated package id.
     *
     * @param  \Illuminate\Validation\Validator  $validator
     */
    public function withValidator($validator): void
    {
        $validator->after(function ($validator): void {
            /** @var Member|null $member */
            $member = $this->route('member');

            // Route-model binding already 404s a hard-missing or (SoftDeletes
            // default-scope) trashed member; this catches the deactivated case so
            // a sale never lands on an inactive account (§3.3, §5.4).
            if ($member !== null && ! $member->is_active) {
                $validator->errors()->add('member', __('สมาชิกถูกปิดใช้งาน ขายไม่ได้'));
            }
        });
    }

    protected function prepareForValidation(): void
    {
        // Backfill price_paid from the package list price when omitted/blank, so the
        // PurchaseService always receives a concrete decimal(10,2) string. We read
        // the package price as the stored string (decimal:2 cast) — never a float.
        if (! $this->filled('price_paid')) {
            $package = Package::query()->find($this->input('package_id'));

            $this->merge([
                'price_paid' => $package?->price,
            ]);
        }
    }
}
