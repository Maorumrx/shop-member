<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\BookingOrigin;
use App\Enums\BookingStatus;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Booking (docs/phase7-booking-design.md §3.2) — a member's reservation of a
 * branch time-slot for an intended service. Scheduling data, NOT financial: it
 * holds NO credit and writes NO ledger row on create. The wallet is charged at
 * CHECK-IN via {@see \App\Services\Wallet\WalletService}, which stamps the
 * resulting credit_ledger rows with this booking's id — so a completed booking's
 * consumption is exactly `credit_ledger WHERE booking_id = ?` (§7).
 *
 * v1 AUTO-CONFIRM (client decision): a booking is created directly as
 * `confirmed`; there is no `pending` and no `confirmed_*` audit pair — `created_at`
 * IS the confirmation time. Slot length is fixed per branch and snapshotted onto
 * `slot_length_minutes` at booking time.
 *
 * Soft-deleted (never hard-deleted) so a mistaken row leaves active views while
 * keeping the ledger back-reference intact (§3.2).
 *
 * @property int $id
 * @property int $member_id
 * @property int $branch_id
 * @property string $item_code
 * @property string $item_name
 * @property \Illuminate\Support\Carbon $scheduled_start
 * @property \Illuminate\Support\Carbon $scheduled_end
 * @property int $slot_length_minutes
 * @property BookingStatus $status
 * @property BookingOrigin $created_via
 * @property int|null $created_by_user_id
 * @property \Illuminate\Support\Carbon|null $checked_in_at
 * @property int|null $checked_in_by_user_id
 * @property \Illuminate\Support\Carbon|null $completed_at
 * @property \Illuminate\Support\Carbon|null $cancelled_at
 * @property int|null $cancelled_by_user_id
 * @property string|null $note
 * @property \Illuminate\Support\Carbon|null $reminded_at
 * @property \Illuminate\Support\Carbon|null $deleted_at
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 */
class Booking extends Model
{
    use SoftDeletes;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'member_id',
        'branch_id',
        'item_code',
        'item_name',
        'scheduled_start',
        'scheduled_end',
        'slot_length_minutes',
        'status',
        'created_via',
        'created_by_user_id',
        'checked_in_at',
        'checked_in_by_user_id',
        'completed_at',
        'cancelled_at',
        'cancelled_by_user_id',
        'note',
        'reminded_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'member_id' => 'integer',
            'branch_id' => 'integer',
            'scheduled_start' => 'datetime',
            'scheduled_end' => 'datetime',
            'slot_length_minutes' => 'integer',
            'status' => BookingStatus::class,
            'created_via' => BookingOrigin::class,
            'created_by_user_id' => 'integer',
            'checked_in_at' => 'datetime',
            'checked_in_by_user_id' => 'integer',
            'completed_at' => 'datetime',
            'cancelled_at' => 'datetime',
            'cancelled_by_user_id' => 'integer',
            'reminded_at' => 'datetime',
            'deleted_at' => 'datetime',
        ];
    }

    /**
     * The member the booking is for.
     *
     * @return BelongsTo<Member, $this>
     */
    public function member(): BelongsTo
    {
        return $this->belongsTo(Member::class);
    }

    /**
     * The branch hosting the slot.
     *
     * @return BelongsTo<Branch, $this>
     */
    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    /**
     * The staff/owner who created a `staff`-origin booking; null for member
     * self-bookings (no `users` row for a member).
     *
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    /**
     * The credit-ledger rows this booking's check-in debit produced (soft edge via
     * `credit_ledger.booking_id`). Zero rows until check-in; one row per credit_lot
     * the charge walked (FIFO), all sharing this booking_id (§7).
     *
     * N+1: eager-load with `Booking::with('ledgerEntries')` when rendering
     * "what did this booking consume".
     *
     * @return HasMany<CreditLedger, $this>
     */
    public function ledgerEntries(): HasMany
    {
        return $this->hasMany(CreditLedger::class, 'booking_id');
    }

    /**
     * Upcoming, still-live bookings ordered soonest-first: status `confirmed`
     * with `scheduled_start >= now`. Serves the member "my upcoming bookings"
     * list via I15 `(member_id, scheduled_start)`.
     *
     * @param  Builder<Booking>  $query
     * @return Builder<Booking>
     */
    public function scopeUpcoming(Builder $query): Builder
    {
        return $query
            ->where('status', BookingStatus::Confirmed)
            ->where('scheduled_start', '>=', now())
            ->orderBy('scheduled_start');
    }

    /**
     * All of a branch's bookings whose slot starts within the given local day
     * `[00:00, next-day 00:00)`, ordered by slot then id. Serves the admin
     * day-view and the availability count via I14
     * `(branch_id, scheduled_start, status)`.
     *
     * @param  Builder<Booking>  $query
     * @return Builder<Booking>
     */
    public function scopeForBranchDay(Builder $query, int $branchId, CarbonInterface $date): Builder
    {
        $dayStart = $date->copy()->startOfDay();
        $dayEnd = $dayStart->copy()->addDay();

        return $query
            ->where('branch_id', $branchId)
            ->where('scheduled_start', '>=', $dayStart)
            ->where('scheduled_start', '<', $dayEnd)
            ->orderBy('scheduled_start')
            ->orderBy('id');
    }
}
