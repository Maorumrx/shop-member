<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Edit a member's basic profile (architecture.md §3.3). Authorization is handled
 * by the route middleware (`role:owner,staff`), so authorize() returns true.
 *
 * Only counter-editable fields are accepted: name, phone, email, is_active.
 * `line_user_id` is NEVER editable here (it is set by the LINE-link flow, §3.3),
 * and members are deactivated via `is_active=false`, never hard-deleted (§5.4).
 * `phone` stays nullable + non-unique (dup phones allowed) — format/length only.
 */
class UpdateMemberRequest extends FormRequest
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
            'phone' => ['nullable', 'string', 'max:20', 'regex:/^[0-9+\-\s]+$/'],
            'email' => ['nullable', 'string', 'email', 'max:190'],
            'is_active' => ['boolean'],
        ];
    }

    protected function prepareForValidation(): void
    {
        // Blank optionals → null; on update is_active is a real toggle — default
        // false so an unchecked box deactivates rather than keeping the old value.
        $this->merge([
            'phone' => $this->filled('phone') ? $this->input('phone') : null,
            'email' => $this->filled('email') ? $this->input('email') : null,
            'is_active' => $this->boolean('is_active'),
        ]);
    }
}
