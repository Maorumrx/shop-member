<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Update a `topup_offers` preset. Owner-only (route middleware `role:owner`).
 *
 * Editing a preset NEVER touches already-sold `credit_lots` (amounts are
 * snapshotted at sale). Money columns are decimal(10,2) strings (§5.6).
 */
class UpdateTopupOfferRequest extends FormRequest
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
            'amount' => ['required', 'numeric', 'decimal:0,2', 'gt:0', 'max:99999999.99'],
            'bonus' => ['required', 'numeric', 'decimal:0,2', 'min:0', 'max:99999999.99'],
            'is_active' => ['boolean'],
            'sort_order' => ['integer', 'min:0', 'max:65535'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'bonus' => ($this->input('bonus') === '' || $this->input('bonus') === null)
                ? '0'
                : $this->input('bonus'),
            // On update is_active is a real toggle — default false so an unchecked
            // box hides the preset.
            'is_active' => $this->boolean('is_active', false),
            'sort_order' => ($this->input('sort_order') === '' || $this->input('sort_order') === null)
                ? 0
                : $this->input('sort_order'),
        ]);
    }
}
