<?php

declare(strict_types=1);

namespace App\Http\Requests\Member;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Member self-booking (Phase 7, docs/phase7-booking-design.md §6). Authorization
 * is the route middleware (`auth:members`); the booking is always created for the
 * authenticated member ($request->user('members')), so authorize() returns true
 * and there is no member_id in the payload (it is never client-supplied).
 *
 * Validates request SHAPE only; all scheduling rules (branch bookable, slot
 * on-grid / in-window / not-past / within-advance, capacity under lock, member
 * active, same-slot duplicate) are the domain concern of
 * {@see \App\Services\Booking\BookingService::create()}, which throws a
 * {@see \App\Services\Booking\BookingException} the controller turns into a clean
 * Thai error toast.
 *
 *   - `branch_id` must reference a branch that is currently bookable
 *     (has a settings row with is_bookable=true) — the scoped exists rule.
 *   - `item_code` is a required non-empty string ≤ 40 (the intended service code;
 *     existence in the live catalog is NOT required — a member may book intent for
 *     something they'll buy at the counter, §3.2).
 *   - `scheduled_start` is a required ISO8601/parseable datetime; grid-alignment
 *     is enforced by the service, not here.
 *   - `note` optional free text ≤ 255.
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
        return [
            // Must be a bookable branch (settings row exists + is_bookable=true).
            // The service re-checks under a lock (race-safe); this is the friendly
            // up-front rejection.
            'branch_id' => [
                'required',
                'integer',
                'exists:branch_booking_settings,branch_id',
            ],
            'item_code' => ['required', 'string', 'max:40'],
            // Parseable datetime; grid/window/past/advance checks live in the service.
            'scheduled_start' => ['required', 'date'],
            'note' => ['nullable', 'string', 'max:255'],
        ];
    }
}
