<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use App\Http\Requests\Admin\Concerns\PackageValidationRules;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Create a catalog package with its nested package_lines (architecture.md §3.4,
 * §3.5). Authorization is handled by the route middleware (`role:owner`).
 */
class StorePackageRequest extends FormRequest
{
    use PackageValidationRules;

    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return $this->packageRules();
    }

    protected function prepareForValidation(): void
    {
        // New packages default to active.
        $this->normalizePackageInput(defaultActive: true);
    }

    /**
     * @param  \Illuminate\Validation\Validator  $validator
     */
    public function withValidator($validator): void
    {
        // No two submitted lines may share an item_code (mirrors DB unique
        // (package_id, item_code), §3.5).
        $this->validateUniqueLineCodes($validator);
    }
}
