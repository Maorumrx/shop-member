<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\EntitlementStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * MemberPackage (architecture.md §3.6) — one owned "lot" per purchase: the unit
 * of per-lot expiry and the parent of its entitlements. A FINANCIAL record, not
 * catalog: `branch_id` is the snapshotted redemption scope (null = any-branch,
 * §5.5) and `price_paid` is what was actually charged. `package_id` survives
 * catalog cleanup (SET NULL) as a provenance hint only (§5.1). The shared
 * active/expired/used_up lifecycle is a rollup of its entitlements (§5.7).
 *
 * @property int $id
 * @property int $member_id
 * @property int|null $package_id
 * @property int|null $branch_id
 * @property \Illuminate\Support\Carbon $purchased_at
 * @property \Illuminate\Support\Carbon|null $expires_at
 * @property string $price_paid       decimal(10,2) — kept as string for exactness (§5.6)
 * @property EntitlementStatus $status
 */
class MemberPackage extends Model
{
    /**
     * @var list<string>
     */
    protected $fillable = [
        'member_id',
        'package_id',
        'branch_id',
        'purchased_at',
        'expires_at',
        'price_paid',
        'status',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'member_id' => 'integer',
            'package_id' => 'integer',
            'branch_id' => 'integer',
            'purchased_at' => 'datetime',
            'expires_at' => 'datetime',
            'price_paid' => 'decimal:2',
            'status' => EntitlementStatus::class,
        ];
    }

    /**
     * Owner member.
     *
     * @return BelongsTo<Member, $this>
     */
    public function member(): BelongsTo
    {
        return $this->belongsTo(Member::class);
    }

    /**
     * Source package (provenance hint; nullable after catalog cleanup).
     *
     * @return BelongsTo<Package, $this>
     */
    public function package(): BelongsTo
    {
        return $this->belongsTo(Package::class);
    }

    /**
     * Snapshotted redemption-scope branch; null = any-branch (§5.5).
     *
     * @return BelongsTo<Branch, $this>
     */
    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    /**
     * Per-item entitlements yielded by this lot (one per package line).
     *
     * N+1: the lot-detail screen renders each entitlement's ledger — eager-load
     * with `MemberPackage::with(['entitlements.ledgerEntries'])` (§6.4).
     *
     * @return HasMany<Entitlement, $this>
     */
    public function entitlements(): HasMany
    {
        return $this->hasMany(Entitlement::class);
    }
}
