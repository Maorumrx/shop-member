<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\CreditLotStatus;
use App\Enums\CreditSource;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * CreditLot — one owned "lot" per top-up batch (the money-wallet reframe of the
 * dropped MemberPackage). A FINANCIAL record: the unit of optional per-lot expiry
 * and the unit that keeps PAID money and BONUS money separate.
 *
 * `amount_paid`/`bonus_amount` are frozen at sale; `paid_remaining`/`bonus_remaining`
 * are the reconcilable READ CACHE of the append-only {@see CreditLedger} — the lot's
 * spendable value is `paid_remaining + bonus_remaining`. Debits spend `bonus_remaining`
 * before `paid_remaining` within a lot; a refund reverses `paid_remaining` only.
 *
 * `expires_at` is nullable (null = never); the expiry capability is built but stays
 * OFF until the client sets a policy. Never mass-assign the `*_remaining` columns
 * from request input — they are ledger-derived and must be moved server-side by the
 * top-up / debit / refund services so they can't desync from the ledger.
 *
 * @property int $id
 * @property int $member_id
 * @property CreditSource $source
 * @property string $amount_paid       decimal(10,2) — kept as string for exactness (§5.6)
 * @property string $bonus_amount      decimal(10,2)
 * @property string $paid_remaining    decimal(10,2) — ledger-derived cache
 * @property string $bonus_remaining   decimal(10,2) — ledger-derived cache
 * @property \Illuminate\Support\Carbon|null $expires_at
 * @property \Illuminate\Support\Carbon|null $expiry_reminded_at
 * @property CreditLotStatus $status
 * @property \Illuminate\Support\Carbon $purchased_at
 * @property int|null $branch_id
 * @property int|null $created_by_user_id
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 */
class CreditLot extends Model
{
    /**
     * @var list<string>
     */
    protected $fillable = [
        'member_id',
        'source',
        'amount_paid',
        'bonus_amount',
        'paid_remaining',
        'bonus_remaining',
        'expires_at',
        'expiry_reminded_at',
        'status',
        'purchased_at',
        'branch_id',
        'created_by_user_id',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'member_id' => 'integer',
            'source' => CreditSource::class,
            'amount_paid' => 'decimal:2',
            'bonus_amount' => 'decimal:2',
            'paid_remaining' => 'decimal:2',
            'bonus_remaining' => 'decimal:2',
            'expires_at' => 'datetime',
            'expiry_reminded_at' => 'datetime',
            'status' => CreditLotStatus::class,
            'purchased_at' => 'datetime',
            'branch_id' => 'integer',
            'created_by_user_id' => 'integer',
        ];
    }

    /**
     * Owner member. RESTRICT at the DB level — members are soft-deleted only (§5.4).
     *
     * @return BelongsTo<Member, $this>
     */
    public function member(): BelongsTo
    {
        return $this->belongsTo(Member::class);
    }

    /**
     * Snapshotted branch where the top-up happened; null = any-branch (§5.5-style).
     *
     * @return BelongsTo<Branch, $this>
     */
    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    /**
     * Staff/owner who performed the top-up/adjustment; null if that user was later
     * removed (SET NULL) or for a system-created lot.
     *
     * @return BelongsTo<User, $this>
     */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    /**
     * Append-only money movements for this lot, replayable in insertion order via
     * idx_credit_ledger_lot_id `(credit_lot_id, id)`.
     *
     * N+1: the lot-detail / statement view renders these with their staff —
     * eager-load with `CreditLot::with('ledgerEntries.staff')`.
     *
     * @return HasMany<CreditLedger, $this>
     */
    public function ledgerEntries(): HasMany
    {
        return $this->hasMany(CreditLedger::class);
    }

    /**
     * Active (spendable) lots only.
     *
     * @param  Builder<CreditLot>  $query
     * @return Builder<CreditLot>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', CreditLotStatus::Active);
    }
}
