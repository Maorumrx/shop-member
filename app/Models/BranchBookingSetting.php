<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * BranchBookingSetting (docs/phase7-booking-design.md §3.1) — the per-branch slot
 * config (1:1 with `branches`). Defines the uniform daily booking grid:
 * `open_time`..`close_time` in `slot_length_minutes` steps, up to `slot_capacity`
 * concurrent bookings per slot, bookable up to `max_advance_days` ahead. Toggled
 * by `is_bookable`.
 *
 * This row also serves as the per-branch MUTEX for concurrency-safe booking
 * creation: {@see \App\Services\Booking\BookingService::create()} locks it FOR
 * UPDATE before counting a slot (§5.4 Strategy A).
 *
 * `open_time`/`close_time` are 'H:i:s' TIME columns kept as plain strings (no
 * datetime cast) so slot-grid math composes them onto a chosen date explicitly.
 *
 * @property int $id
 * @property int $branch_id
 * @property bool $is_bookable
 * @property int $slot_capacity
 * @property int $slot_length_minutes
 * @property string $open_time             'H:i:s'
 * @property string $close_time            'H:i:s'
 * @property int $max_advance_days
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 */
class BranchBookingSetting extends Model
{
    /**
     * @var list<string>
     */
    protected $fillable = [
        'branch_id',
        'is_bookable',
        'slot_capacity',
        'slot_length_minutes',
        'open_time',
        'close_time',
        'max_advance_days',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'branch_id' => 'integer',
            'is_bookable' => 'boolean',
            'slot_capacity' => 'integer',
            'slot_length_minutes' => 'integer',
            'max_advance_days' => 'integer',
            // open_time/close_time are TIME columns — left uncast (plain 'H:i:s'
            // strings) so the slot-grid builder composes them onto a date itself.
        ];
    }

    /**
     * The branch this config governs (1:1).
     *
     * @return BelongsTo<Branch, $this>
     */
    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }
}
