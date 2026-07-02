<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use App\Models\Member;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Staff books on behalf of a member (Phase 7, docs/phase7-booking-design.md §6).
 * Authorization is the route middleware (`role:owner,staff`) so authorize()
 * returns true; the acting staff is recorded as `created_by_user_id`
 * (created_via=staff).
 *
 * Validates request SHAPE + member/branch preconditions; all scheduling rules
 * (slot on-grid / in-window / not-past / within-advance, capacity under lock,
 * same-slot duplicate) are resolved (and locked) inside
 * {@see \App\Services\Booking\BookingService::create()}.
 *
 *   - `member_id` exists (staff choose the member — unlike the member side, where
 *     it's the authenticated member). Deactivated members are rejected in
 *     withValidator (the service also re-guards).
 *   - `branch_id` must be a currently-bookable branch. For STAFF, it is further
 *     constrained to their OWN home branch (owner may book any) — mirrors the
 *     redemption branch-scoping stance (§5.5).
 *   - `item_code` required ≤ 40 (intended service; catalog existence not required).
 *   - `scheduled_start` parseable datetime; grid-alignment enforced by the service.
 *   - `note` optional ≤ 255.
 */
class StoreBookingRequest extends FormRequest
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
        $user = $this->user();

        return [
            'member_id' => ['required', 'integer', 'exists:members,id'],
            'branch_id' => [
                'required',
                'integer',
                'exists:branch_booking_settings,branch_id',
                // Branch-scope staff to their own branch; an owner (branch_id null)
                // gets no `in:` restriction and may book any bookable branch (§5.5).
                Rule::when(
                    $user !== null && $user->isStaff() && $user->branch_id !== null,
                    ['in:' . ($user?->branch_id ?? 0)],
                ),
            ],
            'item_code' => ['required', 'string', 'max:40'],
            'scheduled_start' => ['required', 'date'],
            'note' => ['nullable', 'string', 'max:255'],
        ];
    }

    /**
     * Reject booking for an inactive member (mirrors the wallet action requests,
     * §3.3, §5.4). The service re-guards member status inside create().
     *
     * @param  \Illuminate\Validation\Validator  $validator
     */
    public function withValidator($validator): void
    {
        $validator->after(function ($validator): void {
            $memberId = $this->integer('member_id');

            if ($memberId <= 0) {
                return;
            }

            /** @var Member|null $member */
            $member = Member::query()->find($memberId);

            if ($member !== null && ! $member->is_active) {
                $validator->errors()->add('member_id', __('สมาชิกถูกปิดใช้งาน จองไม่ได้'));
            }
        });
    }
}
