<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\EntitlementStatus;
use App\Enums\ItemType;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Entitlement (architecture.md §3.7) — one owned, per-item row per package line
 * of a purchased lot. All item descriptors (`item_code`, `item_name`,
 * `item_type`, `qty_total`, `redeem_group`, `expires_at`) are SNAPSHOTS frozen
 * at purchase and never re-read from the catalog (§5.1). `member_id` and
 * `expires_at` are denormalized so the hot redemption query (§3.7, I1) needs no
 * join. `qty_remaining` is a DERIVED READ CACHE = `qty_total + Σ ledger.delta`;
 * the append-only `entitlement_ledger` is the source of truth and the reconcile
 * command (§6.1) can rebuild this cache at any time.
 *
 * @property int $id
 * @property int $member_package_id
 * @property int $member_id
 * @property string $item_code
 * @property string $item_name
 * @property ItemType $item_type
 * @property int $qty_total
 * @property int $qty_remaining
 * @property string|null $redeem_group
 * @property \Illuminate\Support\Carbon|null $expires_at
 * @property EntitlementStatus $status
 */
class Entitlement extends Model
{
    /**
     * @var list<string>
     */
    // TODO(Phase 5): never mass-assign `qty_remaining` from request input — it is
    // the ledger-derived cache; set it server-side via the redemption/reconcile
    // services so it can't desync from entitlement_ledger (§5.2, §6.1).
    protected $fillable = [
        'member_package_id',
        'member_id',
        'item_code',
        'item_name',
        'item_type',
        'qty_total',
        'qty_remaining',
        'redeem_group',
        'expires_at',
        'status',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'member_package_id' => 'integer',
            'member_id' => 'integer',
            'item_type' => ItemType::class,
            'qty_total' => 'integer',
            'qty_remaining' => 'integer',
            'expires_at' => 'datetime',
            'status' => EntitlementStatus::class,
        ];
    }

    /**
     * Parent lot.
     *
     * @return BelongsTo<MemberPackage, $this>
     */
    public function memberPackage(): BelongsTo
    {
        return $this->belongsTo(MemberPackage::class);
    }

    /**
     * Denormalized owner member (§3.7).
     *
     * @return BelongsTo<Member, $this>
     */
    public function member(): BelongsTo
    {
        return $this->belongsTo(Member::class);
    }

    /**
     * Append-only movement history for this entitlement, replayable in
     * insertion order via I5 `(entitlement_id, id)`.
     *
     * N+1: the lot-detail / statement view renders ledger rows with their staff
     * — eager-load with `Entitlement::with('ledgerEntries.staff')` (§6.4).
     *
     * @return HasMany<EntitlementLedger, $this>
     */
    public function ledgerEntries(): HasMany
    {
        return $this->hasMany(EntitlementLedger::class);
    }

    /**
     * Active (redeemable) entitlements only.
     *
     * @param  Builder<Entitlement>  $query
     * @return Builder<Entitlement>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', EntitlementStatus::Active);
    }

    /**
     * Redeemable-at-branch set: active, with qty left, not yet expired, and
     * branch-eligible via the parent lot's snapshotted scope
     * (`branch_id` null = any-branch, else must equal `$branchId`; §5.5).
     *
     * This is the lookup half of the FIFO redemption query (architecture.md
     * §6.3, index I1). The Phase-5 RedemptionService composes it inside a
     * `DB::transaction` with `->lockForUpdate()` and
     * `->orderByRaw('expires_at IS NULL asc, expires_at asc')` so never-expiring
     * lots are consumed last and concurrent redemptions can't double-spend.
     *
     * @param  Builder<Entitlement>  $query
     * @return Builder<Entitlement>
     */
    public function scopeRedeemableAt(Builder $query, int $branchId): Builder
    {
        return $query
            ->where('status', EntitlementStatus::Active)
            ->where('qty_remaining', '>', 0)
            ->where(function (Builder $q): void {
                $q->whereNull('expires_at')
                    ->orWhere('expires_at', '>', now());
            })
            ->whereHas('memberPackage', function (Builder $q) use ($branchId): void {
                $q->whereNull('branch_id')
                    ->orWhere('branch_id', $branchId);
            });
    }
}
