<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Create a `topup_offers` preset — a quick-pick "pay `amount` → get
 * `amount + bonus` spendable" button for the sell screen. Owner-only (route
 * middleware `role:owner`).
 *
 * Changing/creating an offer NEVER touches already-sold `credit_lots` (a lot
 * snapshots its amounts at sale). Both money columns are decimal(10,2) strings
 * (§5.6), never float; `bonus` defaults to 0 (a no-bonus preset is valid).
 */
class StoreTopupOfferRequest extends FormRequest
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
            'name' => ['required', 'string', 'max:150'],
            // Cash the customer pays. Must be > 0 — a preset that costs nothing is
            // meaningless (a pure-bonus grant is the owner adjust path, not a sale).
            'amount' => ['required', 'numeric', 'decimal:0,2', 'gt:0', 'max:99999999.99'],
            // Promotional bonus; 0 = no bonus. Never negative.
            'bonus' => ['required', 'numeric', 'decimal:0,2', 'min:0', 'max:99999999.99'],
            'is_active' => ['boolean'],
            'sort_order' => ['integer', 'min:0', 'max:65535'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            // Blank bonus → 0 (a no-bonus preset).
            'bonus' => ($this->input('bonus') === '' || $this->input('bonus') === null)
                ? '0'
                : $this->input('bonus'),
            'is_active' => $this->boolean('is_active', true),
            // Blank sort_order → 0 (matches the DB default).
            'sort_order' => ($this->input('sort_order') === '' || $this->input('sort_order') === null)
                ? 0
                : $this->input('sort_order'),
        ]);
    }
}
