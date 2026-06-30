<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use App\Models\Branch;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Update a catalog branch (architecture.md §3.1). Authorization is handled by
 * the route middleware (`role:owner`), so authorize() returns true.
 */
class UpdateBranchRequest extends FormRequest
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
        /** @var Branch $branch */
        $branch = $this->route('branch');

        return [
            // Unique name ignoring the row being edited (§3.1 unique constraint).
            'name' => ['required', 'string', 'max:120', Rule::unique('branches', 'name')->ignore($branch->id)],
            'is_active' => ['boolean'],
        ];
    }

    protected function prepareForValidation(): void
    {
        // is_active is a real toggle on update — default to false when omitted so
        // an unchecked box deactivates rather than silently keeping the old value.
        $this->merge([
            'is_active' => $this->boolean('is_active'),
        ]);
    }
}
