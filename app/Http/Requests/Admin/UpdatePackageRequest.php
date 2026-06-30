<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use App\Http\Requests\Admin\Concerns\PackageValidationRules;
use App\Models\Package;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Update a catalog package and sync its nested package_lines (architecture.md
 * §3.4, §3.5). Authorization is handled by the route middleware (`role:owner`).
 *
 * NOTE: editing catalog lines NEVER touches sold entitlements — those are
 * value-copied snapshots taken at purchase (§5.1). This form only mutates the
 * catalog definition.
 */
class UpdatePackageRequest extends FormRequest
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
        // On update is_active is a real toggle — default false so an unchecked
        // box deactivates the package rather than keeping the stored value.
        $this->normalizePackageInput(defaultActive: false);
    }

    /**
     * @param  \Illuminate\Validation\Validator  $validator
     */
    public function withValidator($validator): void
    {
        // No two submitted lines may share an item_code (DB unique, §3.5).
        $this->validateUniqueLineCodes($validator);

        // Any line carrying an `id` must belong to THIS package — guards against
        // a tampered payload re-parenting another package's line during sync.
        $validator->after(function ($validator): void {
            /** @var Package $package */
            $package = $this->route('package');

            $submittedIds = collect((array) $this->input('lines', []))
                ->pluck('id')
                ->filter(fn ($id) => $id !== null && $id !== '')
                ->map(fn ($id) => (int) $id)
                ->all();

            if ($submittedIds === []) {
                return;
            }

            $ownedIds = $package->lines()->pluck('id')->all();
            $foreign = array_diff($submittedIds, $ownedIds);

            if ($foreign !== []) {
                $validator->errors()->add('lines', __('รายการบางรายการไม่ได้อยู่ในแพ็คเกจนี้'));
            }
        });
    }
}
