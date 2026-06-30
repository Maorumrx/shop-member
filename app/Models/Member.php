<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

/**
 * Member (architecture.md §3.3) — the customer identity on the `members`
 * guard. Supports LINE self-register (nullable+unique `line_user_id`,
 * nullable `password`) and admin-created counter accounts (link LINE later).
 *
 * Members are NEVER hard-deleted: this model uses SoftDeletes and every child
 * FK (member_packages, entitlements, entitlement_ledger) is ON DELETE RESTRICT
 * to protect the append-only financial/audit ledger (§5.4). Disable via
 * `is_active = false`.
 *
 * @property int $id
 * @property string|null $line_user_id
 * @property string $name
 * @property string|null $phone
 * @property string|null $email
 * @property string|null $avatar_url
 * @property int|null $default_branch_id
 * @property string|null $password
 * @property bool $is_active
 * @property \Illuminate\Support\Carbon|null $deleted_at
 */
class Member extends Authenticatable
{
    use Notifiable;
    use SoftDeletes;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'line_user_id',
        'name',
        'phone',
        'email',
        'avatar_url',
        'default_branch_id',
        'password',
        'is_active',
    ];

    /**
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'default_branch_id' => 'integer',
            'is_active' => 'boolean',
            'password' => 'hashed',
            'deleted_at' => 'datetime',
        ];
    }

    /**
     * Home-branch hint (`members.default_branch_id`). Eager-load on the admin
     * members list to avoid N+1 (`Member::with('defaultBranch')`, §6.4).
     *
     * @return BelongsTo<Branch, $this>
     */
    public function defaultBranch(): BelongsTo
    {
        return $this->belongsTo(Branch::class, 'default_branch_id');
    }

    /**
     * Owned lots (one row per purchase).
     *
     * N+1: the member dashboard renders lots and their items — eager-load with
     * `Member::with(['memberPackages.entitlements'])` (§6.4).
     *
     * @return HasMany<MemberPackage, $this>
     */
    public function memberPackages(): HasMany
    {
        return $this->hasMany(MemberPackage::class);
    }

    /**
     * Per-item entitlements held by this member (denormalized `member_id` so
     * the hot redemption query needs no lot join, §3.7).
     *
     * @return HasMany<Entitlement, $this>
     */
    public function entitlements(): HasMany
    {
        return $this->hasMany(Entitlement::class);
    }

    /**
     * Member-level ledger movements (denormalized `member_id` for statements
     * without a join). Serves the activity feed via I6 `(member_id, created_at)`.
     *
     * @return HasMany<EntitlementLedger, $this>
     */
    public function ledgerEntries(): HasMany
    {
        return $this->hasMany(EntitlementLedger::class);
    }
}
