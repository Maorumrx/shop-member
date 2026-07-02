<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\CreditLedgerReason;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * CreditLedger — the APPEND-ONLY money ledger and single source of truth (the
 * money-wallet reframe of the dropped EntitlementLedger). Every wallet movement is
 * one immutable row: `delta` (signed baht), `reason`, and `balance_after` (the
 * member's TOTAL spendable wallet balance AFTER this row applied).
 *
 * THE INVARIANT: a member's spendable balance ==
 * SUM(active credit_lots.paid_remaining + bonus_remaining) == the member's latest
 * `balance_after`; per lot, (paid_remaining + bonus_remaining) ==
 * (amount_paid + bonus_amount) + Σ(delta for that credit_lot_id). The lot remainings
 * are a disposable cache rebuildable from this ledger.
 *
 * IMMUTABILITY IS STRUCTURAL: no `updated_at` (`const UPDATED_AT = null`). Rows are
 * NEVER updated or deleted — UPDATE/DELETE are FORBIDDEN (backed by the runtime
 * guards below). Corrections are an appended row (reason=refund/adjust), never an
 * edit to history. `created_at` is the only timestamp, set once at insert.
 *
 * @property int $id
 * @property int $member_id
 * @property int|null $credit_lot_id
 * @property string $delta          decimal(10,2) SIGNED — kept as string (§5.6)
 * @property CreditLedgerReason $reason
 * @property string $balance_after  decimal(10,2) — member TOTAL wallet balance after this row
 * @property int|null $booking_id
 * @property int|null $staff_id
 * @property string|null $note
 * @property \Illuminate\Support\Carbon $created_at
 */
class CreditLedger extends Model
{
    /**
     * Table is singular (`credit_ledger`) — override Laravel's plural guess.
     */
    protected $table = 'credit_ledger';

    /**
     * Append-only: no `updated_at` column — rows are immutable.
     */
    public const UPDATED_AT = null;

    /**
     * `created_at` is fillable because ledger rows are written explicitly within the
     * top-up / debit / refund / expiry transactions; the table has no `updated_at`.
     *
     * @var list<string>
     */
    protected $fillable = [
        'member_id',
        'credit_lot_id',
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
            'member_id' => 'integer',
            'credit_lot_id' => 'integer',
            'delta' => 'decimal:2',
            'reason' => CreditLedgerReason::class,
            'balance_after' => 'decimal:2',
            'booking_id' => 'integer',
            'staff_id' => 'integer',
            'created_at' => 'datetime',
        ];
    }

    /**
     * Enforce append-only at the model layer: UPDATE and DELETE are forbidden.
     * Corrections are an appended row (reason=refund/adjust), never an edit to
     * history. Backs up the structural immutability (no `updated_at`) with a
     * runtime guard — identical to the dropped EntitlementLedger.
     */
    protected static function booted(): void
    {
        static::updating(static function (): void {
            throw new \RuntimeException(
                'credit_ledger is append-only — updates are forbidden; append a refund/adjust row instead.'
            );
        });

        static::deleting(static function (): void {
            throw new \RuntimeException('credit_ledger is append-only — deletes are forbidden.');
        });
    }

    /**
     * Denormalized owner member (enables member-level statements without a join, via
     * idx_credit_ledger_member_created `(member_id, created_at)`).
     *
     * @return BelongsTo<Member, $this>
     */
    public function member(): BelongsTo
    {
        return $this->belongsTo(Member::class);
    }

    /**
     * The lot this row moved; null for a system/adjust row not bound to one lot, or
     * if a lot was ever removed (SET NULL).
     *
     * @return BelongsTo<CreditLot, $this>
     */
    public function creditLot(): BelongsTo
    {
        return $this->belongsTo(CreditLot::class);
    }

    /**
     * The booking whose check-in produced this debit; null for counter/top-up rows.
     *
     * @return BelongsTo<Booking, $this>
     */
    public function booking(): BelongsTo
    {
        return $this->belongsTo(Booking::class);
    }

    /**
     * Admin user who performed the movement; null for system jobs (e.g. expiry).
     *
     * N+1: the statement / activity feed shows who did each movement — eager-load
     * with `CreditLedger::with('staff')` selecting explicit columns.
     *
     * @return BelongsTo<User, $this>
     */
    public function staff(): BelongsTo
    {
        return $this->belongsTo(User::class, 'staff_id');
    }
}
