<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Create a `services` price-list row (the money-wallet reframe of the dropped
 * package catalog). Owner-only — authorization is the route middleware
 * (`role:owner`), so authorize() returns true.
 *
 * `item_code` is GLOBALLY UNIQUE (one canonical price per business code, shared
 * with `bookings.item_code`); `price` is a decimal(10,2) string (§5.6), NEVER a
 * float. `branch_id` is an optional scope hint (null = any-branch price).
 */
class StoreServiceRequest extends FormRequest
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
            // Stable business code the debit path + bookings consume. UNIQUE across
            // the whole table (one price per code, no per-branch multiplication).
            'item_code' => ['required', 'string', 'max:40', 'unique:services,item_code'],
            'name' => ['required', 'string', 'max:150'],
            // decimal:0,2 ⇒ at most 2 fractional digits; min:0 forbids negatives;
            // max keeps it inside decimal(10,2) (§5.6). Kept as a string, never float.
            'price' => ['required', 'numeric', 'decimal:0,2', 'min:0', 'max:99999999.99'],
            // null = priced at ANY branch; otherwise must exist.
            'branch_id' => ['nullable', 'integer', 'exists:branches,id'],
            'is_active' => ['boolean'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            // Empty branch_id → null (any-branch).
            'branch_id' => ($this->input('branch_id') === '' || $this->input('branch_id') === null)
                ? null
                : $this->input('branch_id'),
            // New services default to active (matches the DB default).
            'is_active' => $this->boolean('is_active', true),
        ]);
    }
}
