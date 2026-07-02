<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * Branch (architecture.md §3.1) — physical shop and the scoping unit for the
 * wallet/booking context (§5.5). Mutable reference data; toggled off via
 * `is_active` rather than deleted (bookings on a branch RESTRICT delete).
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
     * Scope to active branches only (`is_active = true`). Used by the catalog
     * admin to populate branch pickers (Service create/edit) — inactive
     * branches stay selectable on existing services but aren't offered for new
     * scoping (architecture.md §3.1, §3.4).
     *
     * @param  Builder<Branch>  $query
     * @return Builder<Branch>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
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

    /**
     * Per-branch booking config (1:1). Absent (null) for a branch that never
     * takes bookings; `is_bookable=false` also disables an existing config
     * (Phase 7, docs/phase7-booking-design.md §3.1).
     *
     * @return HasOne<BranchBookingSetting, $this>
     */
    public function bookingSetting(): HasOne
    {
        return $this->hasOne(BranchBookingSetting::class);
    }

    /**
     * Bookings hosted at this branch (Phase 7). RESTRICT on delete — a branch
     * with bookings on the calendar isn't silently deletable (§3.2).
     *
     * @return HasMany<Booking, $this>
     */
    public function bookings(): HasMany
    {
        return $this->hasMany(Booking::class);
    }

    /**
     * Price-list services scoped to this branch (`services.branch_id`). A null
     * `branch_id` service is any-branch and is NOT returned here.
     *
     * @return HasMany<Service, $this>
     */
    public function services(): HasMany
    {
        return $this->hasMany(Service::class);
    }

    /**
     * Credit-wallet lots whose top-up branch was snapshotted to this one
     * (`credit_lots.branch_id`). SET NULL on delete (lot becomes any-branch).
     *
     * @return HasMany<CreditLot, $this>
     */
    public function creditLots(): HasMany
    {
        return $this->hasMany(CreditLot::class);
    }
}
