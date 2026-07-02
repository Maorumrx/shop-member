<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use App\Models\Service;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Update a `services` price-list row. Owner-only (route middleware `role:owner`).
 *
 * GOLDEN RULE: editing a price NEVER rewrites past debits — every credit_ledger
 * debit row froze the baht taken at the time (§5.6). This form only mutates the
 * live catalog definition. `item_code` uniqueness ignores THIS row.
 */
class UpdateServiceRequest extends FormRequest
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
        /** @var Service $service */
        $service = $this->route('service');

        return [
            // Unique across the table EXCEPT this row.
            'item_code' => [
                'required', 'string', 'max:40',
                Rule::unique('services', 'item_code')->ignore($service->id),
            ],
            'name' => ['required', 'string', 'max:150'],
            'price' => ['required', 'numeric', 'decimal:0,2', 'min:0', 'max:99999999.99'],
            'branch_id' => ['nullable', 'integer', 'exists:branches,id'],
            'is_active' => ['boolean'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'branch_id' => ($this->input('branch_id') === '' || $this->input('branch_id') === null)
                ? null
                : $this->input('branch_id'),
            // On update is_active is a real toggle — default false so an unchecked
            // box hides the service rather than keeping the stored value.
            'is_active' => $this->boolean('is_active', false),
        ]);
    }
}
