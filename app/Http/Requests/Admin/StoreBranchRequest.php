<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Create a catalog branch (architecture.md §3.1). Authorization is handled by
 * the route middleware (`role:owner`), so authorize() returns true.
 */
class StoreBranchRequest extends FormRequest
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
            // Unique branch name (§3.1 unique constraint) — DB also enforces it.
            'name' => ['required', 'string', 'max:120', 'unique:branches,name'],
            'is_active' => ['boolean'],
        ];
    }

    protected function prepareForValidation(): void
    {
        // Default new branches to active when the flag is omitted (matches the
        // DB default is_active=true, §3.1).
        $this->merge([
            'is_active' => $this->boolean('is_active', true),
        ]);
    }
}
