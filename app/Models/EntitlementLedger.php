<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\LedgerReason;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * EntitlementLedger (architecture.md §3.8, §5.2) — the APPEND-ONLY source of
 * truth. Every entitlement movement is one immutable row: `delta` (+/-),
 * `reason`, and `balance_after` (the entitlement's `qty_remaining` AFTER this
 * row applied). The invariant is `qty_remaining == qty_total + Σ delta ==
 * latest balance_after`; `entitlements.qty_remaining` is merely a disposable
 * cache rebuildable from this ledger (§6.1).
 *
 * IMMUTABILITY IS STRUCTURAL: this table has NO `updated_at`
 * (`const UPDATED_AT = null`). Rows are NEVER updated or deleted — UPDATE and
 * DELETE on the ledger are FORBIDDEN. Corrections are made by appending a new
 * row (reason=refund/adjust), never by editing history. `created_at` is the
 * only timestamp and is set once at insert.
 *
 * `booking_id` is a forward-reference to Phase 5 (no `bookings` table / FK yet);
 * the column exists now but must not be repurposed (§3.8).
 *
 * @property int $id
 * @property int $entitlement_id
 * @property int $member_id
 * @property int $delta
 * @property LedgerReason $reason
 * @property int $balance_after
 * @property int|null $booking_id
 * @property int|null $staff_id
 * @property string|null $note
 * @property \Illuminate\Support\Carbon $created_at
 */
class EntitlementLedger extends Model
{
    /**
     * Table is singular (`entitlement_ledger`) per the schema — override Laravel's
     * default plural guess (`entitlement_ledgers`).
     */
    protected $table = 'entitlement_ledger';

    /**
     * Append-only: no `updated_at` column — rows are immutable (§3.8, §5.2).
     */
    public const UPDATED_AT = null;

    /**
     * `created_at` is fillable because ledger rows are written explicitly within
     * the redemption/expiry transactions; the table has no `updated_at`.
     *
     * @var list<string>
     */
    protected $fillable = [
        'entitlement_id',
        'member_id',
        'delta',
        'reason',
        'balance_after',
        'booking_id',
        'staff_id',
        'note',
        'created_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'entitlement_id' => 'integer',
            'member_id' => 'integer',
            'delta' => 'integer',
            'reason' => LedgerReason::class,
            'balance_after' => 'integer',
            'booking_id' => 'integer',
            'staff_id' => 'integer',
            'created_at' => 'datetime',
        ];
    }

    /**
     * Enforce append-only at the model layer: UPDATE and DELETE are forbidden
     * (§3.8, §5.2). Corrections are an appended row (reason=refund/adjust), never
     * an edit to history. This backs up the structural immutability (no
     * `updated_at`) with a runtime guard.
     */
    protected static function booted(): void
    {
        static::updating(static function (): void {
            throw new \RuntimeException(
                'entitlement_ledger is append-only — updates are forbidden; append a refund/adjust row instead.'
            );
        });

        static::deleting(static function (): void {
            throw new \RuntimeException('entitlement_ledger is append-only — deletes are forbidden.');
        });
    }

    /**
     * The entitlement this row moved.
     *
     * @return BelongsTo<Entitlement, $this>
     */
    public function entitlement(): BelongsTo
    {
        return $this->belongsTo(Entitlement::class);
    }

    /**
     * Denormalized owner member (enables member-level statements without a join,
     * via I6 `(member_id, created_at)`; §3.8).
     *
     * @return BelongsTo<Member, $this>
     */
    public function member(): BelongsTo
    {
        return $this->belongsTo(Member::class);
    }

    /**
     * Admin user who performed the movement; null for system jobs (e.g. expiry).
     * `User` ships from the scaffold; Phase 2 adds its `role`/`branch_id` casts.
     *
     * N+1: the statement / activity feed shows who did each movement —
     * eager-load with `EntitlementLedger::with('staff')` (and
     * `entitlement:id,item_name` for the label), selecting explicit columns to
     * keep the payload small (§6.4).
     *
     * @return BelongsTo<User, $this>
     */
    public function staff(): BelongsTo
    {
        return $this->belongsTo(User::class, 'staff_id');
    }
}
