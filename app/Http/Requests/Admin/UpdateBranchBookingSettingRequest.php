<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Owner-only editor for a branch's booking config (Phase 7,
 * docs/phase7-booking-design.md §3.1). Authorization is handled by the route
 * middleware (`role:owner`), so authorize() returns true.
 *
 * Validates the SHAPE the slot grid depends on (open..close in slot_length
 * steps, capacity, advance window). The DB CHECK constraints are MariaDB-only,
 * so the invariants are enforced here rather than relied on at the DB:
 *   - times are wall-clock 'H:i' (persisted as 'H:i:s' TIME columns);
 *   - close_time must be strictly after open_time so at least the grid math has
 *     a non-empty window;
 *   - capacity/length/advance stay within sane operational bounds.
 */
class UpdateBranchBookingSettingRequest extends FormRequest
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
            'is_bookable' => ['boolean'],
            'slot_capacity' => ['required', 'integer', 'min:1', 'max:100'],
            'slot_length_minutes' => ['required', 'integer', 'min:1', 'max:480'],
            // Wall-clock times from the UI (H:i). Persisted as 'H:i:s' TIME below.
            'open_time' => ['required', 'date_format:H:i'],
            // Strictly after open (blocks an inverted/zero window); withValidator()
            // additionally rejects a window too SHORT to fit one slot (§5.1).
            'close_time' => ['required', 'date_format:H:i', 'after:open_time'],
            'max_advance_days' => ['required', 'integer', 'min:0', 'max:365'],
        ];
    }

    protected function prepareForValidation(): void
    {
        // is_bookable is a real toggle on save — default to false when omitted so
        // an unchecked Switch disables booking rather than silently keeping the
        // old value (mirrors UpdateBranchRequest's is_active handling).
        $this->merge([
            'is_bookable' => $this->boolean('is_bookable'),
        ]);
    }

    /**
     * Cross-field guard: the window must be long enough for AT LEAST ONE slot.
     * `after:open_time` already blocks an inverted/zero window, but e.g. 10:00–10:30
     * with a 60-min slot passes yet yields an EMPTY grid — the branch would show
     * "เปิดจอง" while offering no rounds. Reject that at config time.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            // Only meaningful once the shape rules held (valid times + length);
            // otherwise skip so we don't pile a second error onto a bad field.
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            $open = $this->toMinutes((string) $this->input('open_time'));
            $close = $this->toMinutes((string) $this->input('close_time'));

            if ($open === null || $close === null) {
                return;
            }

            if ($close - $open < (int) $this->input('slot_length_minutes')) {
                $validator->errors()->add(
                    'close_time',
                    'ช่วงเวลาเปิด–ปิดต้องยาวพอสำหรับอย่างน้อย 1 รอบ (ตามความยาวช่องที่ตั้งไว้)',
                );
            }
        });
    }

    /**
     * 'H:i' → minutes since midnight, or null when it doesn't parse.
     */
    private function toMinutes(string $time): ?int
    {
        if (! preg_match('/^(\d{1,2}):(\d{2})$/', $time, $m)) {
            return null;
        }

        return ((int) $m[1]) * 60 + (int) $m[2];
    }

    /**
     * The validated payload with `open_time`/`close_time` normalized to the
     * 'H:i:s' TIME shape the column (and BookingService::composeTime) expects —
     * the UI works in H:i, so append ':00' seconds before persisting.
     *
     * @return array<string, mixed>
     */
    public function settingsData(): array
    {
        $data = $this->validated();

        $data['open_time'] .= ':00';
        $data['close_time'] .= ':00';

        return $data;
    }
}
