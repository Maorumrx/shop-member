<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Branch (architecture.md §3.1) — physical shop and the scoping unit for
 * redemption eligibility (§5.5). Mutable reference data; toggled off via
 * `is_active` rather than deleted (packages bound to a branch RESTRICT delete).
 *
 * @property int $id
 * @property string $name
 * @property bool $is_active
 */
class Branch extends Model
{
    /**
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'is_active',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    /**
     * Catalog packages scoped to this branch (`packages.branch_id`).
     * A null `branch_id` package is any-branch and is NOT returned here (§3.4).
     *
     * @return HasMany<Package, $this>
     */
    public function packages(): HasMany
    {
        return $this->hasMany(Package::class);
    }

    /**
     * Owned lots whose redemption scope was snapshotted to this branch (§3.6).
     *
     * @return HasMany<MemberPackage, $this>
     */
    public function memberPackages(): HasMany
    {
        return $this->hasMany(MemberPackage::class);
    }

    /**
     * Admin staff whose home branch is this one (`users.branch_id`).
     * `User` ships from the scaffold; Phase 2 adds its `role`/`branch_id` casts.
     *
     * @return HasMany<User, $this>
     */
    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    /**
     * Members defaulting to this branch (`members.default_branch_id`) — a hint,
     * not an enforcement (§3.3).
     *
     * @return HasMany<Member, $this>
     */
    public function members(): HasMany
    {
        return $this->hasMany(Member::class, 'default_branch_id');
    }
}
