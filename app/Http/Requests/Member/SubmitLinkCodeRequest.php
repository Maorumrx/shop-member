<?php

declare(strict_types=1);

namespace App\Http\Requests\Member;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

/**
 * Submit a 6-digit LINE claim code (docs/member-line-linking-design.md §4.2).
 *
 * This is a PUBLIC endpoint: the customer is authenticated to LINE (their verified
 * `sub` sits in the session under `pending_line`) but is NOT yet on the `members`
 * guard — login happens only AFTER a successful claim. So authorize() returns true
 * and the presence of the pending-LINE session is enforced in the controller
 * (returning a clean "sign in again" error), not here.
 *
 * Validates SHAPE only — exactly 6 digits. Whether the code is live / unexpired /
 * has attempts left / points at a claimable member is the domain concern of
 * {@see \App\Services\Line\MemberLinkService::claim()}, which fails closed with a
 * generic {@see \App\Exceptions\LinkException} the controller turns into a 422
 * that never reveals which member (or why exactly) the code failed.
 */
class SubmitLinkCodeRequest extends FormRequest
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
            // Exactly 6 ASCII digits. `digits:6` also forbids a leading '+', spaces,
            // or non-numeric input; leading zeros are preserved (string, not int).
            'code' => ['required', 'string', 'digits:6'],
        ];
    }

    /**
     * The validated 6-digit code as a string (leading zeros intact — never cast to
     * int, which would drop them and break the hash match).
     */
    public function code(): string
    {
        return (string) $this->validated('code');
    }

    /**
     * Force a clean 422 JSON on a shape failure. This endpoint is called by axios
     * (the LIFF page) and lives under `member/`, NOT `api/*`, so the app's
     * `shouldRenderJsonWhen(api/*)` (bootstrap/app.php) would otherwise REDIRECT a
     * validation failure. Return the same `{ ok, message }` shape the controller
     * uses for its other rejections so the client can surface it uniformly.
     */
    protected function failedValidation(Validator $validator): never
    {
        throw new HttpResponseException(response()->json([
            'ok' => false,
            'message' => 'Please enter the 6-digit code from the shop.',
        ], 422));
    }
}
