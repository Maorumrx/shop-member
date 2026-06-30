<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin\Concerns;

use App\Enums\ItemType;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Validation\Rule;

/**
 * Shared validation for the Package catalog form (create + edit). Both
 * StorePackageRequest and UpdatePackageRequest reuse these so the package +
 * nested package_lines rules never drift apart (architecture.md §3.4, §3.5).
 *
 * Money: `price` is validated as a 2-decimal string and stored decimal:2 —
 * NEVER float (§5.6). `valid_days` null = the sold lot never expires (§3.4).
 */
trait PackageValidationRules
{
    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    protected function packageRules(): array
    {
        $itemTypes = array_column(ItemType::cases(), 'value');

        return [
            // --- Package (catalog header) -----------------------------------
            'name' => ['required', 'string', 'max:150'],
            // decimal:2 enforces at most 2 fractional digits; min:0 forbids
            // negatives. Stored as decimal(10,2) (§5.6) — max keeps it in range.
            'price' => ['required', 'numeric', 'decimal:0,2', 'min:0', 'max:99999999.99'],
            // null = never expires; otherwise a positive integer day count (§3.4).
            'valid_days' => ['nullable', 'integer', 'min:1'],
            // null = redeemable at ANY branch; otherwise must exist (§3.4, §5.5).
            'branch_id' => ['nullable', 'integer', 'exists:branches,id'],
            'is_active' => ['boolean'],

            // --- Lines (nested package_lines) -------------------------------
            // At least one line per package (a package with no items is unsellable).
            'lines' => ['required', 'array', 'min:1'],
            // On update, kept lines carry their existing id so the sync can match
            // them; new lines omit it. The id is verified to belong to THIS
            // package in UpdatePackageRequest::withValidator().
            'lines.*.id' => ['nullable', 'integer'],
            'lines.*.item_code' => ['required', 'string', 'max:40'],
            'lines.*.item_name' => ['required', 'string', 'max:150'],
            'lines.*.item_type' => ['required', Rule::in($itemTypes)],
            'lines.*.qty' => ['required', 'integer', 'min:1'],
            // Add-on coupling label (§5.3): null = independent line. Lines sharing
            // a non-null value redeem together at sale time.
            'lines.*.redeem_group' => ['nullable', 'string', 'max:40'],
        ];
    }

    /**
     * Enforce that `item_code` is UNIQUE within the submitted lines — mirrors the
     * DB unique (package_id, item_code) (§3.5 / I10) so a duplicate is rejected
     * with a field error instead of a 500 on insert.
     *
     * @param  \Illuminate\Validation\Validator  $validator
     */
    protected function validateUniqueLineCodes($validator): void
    {
        $validator->after(function ($validator): void {
            /** @var array<int, array<string, mixed>> $lines */
            $lines = (array) $this->input('lines', []);

            $seen = [];

            foreach ($lines as $index => $line) {
                $code = is_array($line) ? ($line['item_code'] ?? null) : null;

                if ($code === null || $code === '') {
                    continue; // missing/blank codes are already caught by `required`.
                }

                // Case-insensitive compare so e.g. "MASSAGE" and "massage" can't
                // both slip past and then collide depending on DB collation.
                $key = mb_strtolower((string) $code);

                if (isset($seen[$key])) {
                    $validator->errors()->add(
                        "lines.{$index}.item_code",
                        __('รหัสรายการซ้ำกันภายในแพ็คเกจ (item_code ต้องไม่ซ้ำ)'),
                    );

                    continue;
                }

                $seen[$key] = true;
            }
        });
    }

    /**
     * Normalize optional inputs before validation: blank strings → null so
     * `nullable` rules and the DB nullable columns behave consistently, and the
     * package-level is_active flag is coerced to a real boolean.
     */
    protected function normalizePackageInput(bool $defaultActive): void
    {
        $lines = $this->input('lines');

        if (is_array($lines)) {
            $lines = array_map(function ($line) {
                if (! is_array($line)) {
                    return $line;
                }

                // Empty redeem_group → null (independent line, §5.3).
                if (array_key_exists('redeem_group', $line) && $line['redeem_group'] === '') {
                    $line['redeem_group'] = null;
                }

                return $line;
            }, $lines);
        }

        $this->merge([
            'is_active' => $this->boolean('is_active', $defaultActive),
            // Empty valid_days → null (never expires) rather than failing integer.
            'valid_days' => ($this->input('valid_days') === '' || $this->input('valid_days') === null)
                ? null
                : $this->input('valid_days'),
            // Empty branch_id → null (any-branch).
            'branch_id' => ($this->input('branch_id') === '' || $this->input('branch_id') === null)
                ? null
                : $this->input('branch_id'),
            'lines' => $lines,
        ]);
    }
}
