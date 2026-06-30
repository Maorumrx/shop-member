<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Admin-create a counter member (architecture.md §3.3). Authorization is handled
 * by the route middleware (`role:owner,staff`), so authorize() returns true.
 *
 * `line_user_id` is intentionally NOT accepted here: admin-created members start
 * unlinked and link LINE later (§3.3, §5.4). `phone` is nullable and NOT unique —
 * the schema indexes it for counter lookup but allows duplicates (two walk-ins
 * may share a household number), so we validate FORMAT/length only, never
 * uniqueness. `email` is optional.
 */
class StoreMemberRequest extends FormRequest
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
            'name' => ['required', 'string', 'max:120'],
            // Nullable, NOT unique (dup phones allowed, §3.3). Digits/`+`/`-`/space
            // only; max:20 matches the column. THb mobiles fit comfortably.
            'phone' => ['nullable', 'string', 'max:20', 'regex:/^[0-9+\-\s]+$/'],
            'email' => ['nullable', 'string', 'email', 'max:190'],
            'is_active' => ['boolean'],
        ];
    }

    protected function prepareForValidation(): void
    {
        // Blank optionals → null so `nullable` rules and the nullable columns agree;
        // new members default to active (matches the DB default is_active=true, §3.3).
        $this->merge([
            'phone' => $this->filled('phone') ? $this->input('phone') : null,
            'email' => $this->filled('email') ? $this->input('email') : null,
            'is_active' => $this->boolean('is_active', true),
        ]);
    }
}
