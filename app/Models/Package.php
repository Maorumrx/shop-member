<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Package (architecture.md §3.4) — a sellable catalog definition. Mutable,
 * versionless marketing/config data: editing a package NEVER touches sold lots,
 * which snapshot their values at purchase (§5.1). `branch_id` null = redeemable
 * at any branch; `valid_days` null = the sold lot never expires.
 *
 * @property int $id
 * @property string $name
 * @property string $price            decimal(10,2) — kept as string for exactness (§5.6)
 * @property int|null $valid_days
 * @property int|null $branch_id
 * @property bool $is_active
 */
class Package extends Model
{
    /**
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'price',
        'valid_days',
        'branch_id',
        'is_active',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'price' => 'decimal:2',
            'valid_days' => 'integer',
            'branch_id' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    /**
     * Branch this package is scoped to; null = any-branch (§5.5).
     *
     * @return BelongsTo<Branch, $this>
     */
    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    /**
     * Catalog line items (services + add-ons).
     *
     * N+1: the catalog editor lists packages and their lines — eager-load with
     * `Package::with('lines')` (§6.4).
     *
     * @return HasMany<PackageLine, $this>
     */
    public function lines(): HasMany
    {
        return $this->hasMany(PackageLine::class);
    }

    /**
     * Lots sold from this package definition (provenance only — `package_id`
     * is SET NULL on package delete; sold data is already snapshotted, §3.6).
     *
     * @return HasMany<MemberPackage, $this>
     */
    public function memberPackages(): HasMany
    {
        return $this->hasMany(MemberPackage::class);
    }
}
