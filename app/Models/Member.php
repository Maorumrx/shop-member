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
 * FK (credit_lots, credit_ledger) is ON DELETE RESTRICT to protect the
 * append-only financial/audit ledger (§5.4). Disable via `is_active = false`.
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
     * LINE claim codes minted for this member (docs/member-line-linking-design.md
     * §2). Mostly dead rows (consumed/expired) retained for audit; at most ONE is
     * live at a time (service-enforced supersede, MemberLinkService::generate()).
     * Useful for the admin "has a live code?" badge via
     * `whereNull('consumed_at')->where('expires_at', '>', now())`.
     *
     * @return HasMany<MemberLinkCode, $this>
     */
    public function linkCodes(): HasMany
    {
        return $this->hasMany(MemberLinkCode::class);
    }

    /**
     * Credit-wallet lots owned by this member — one row per top-up batch (the
     * money-wallet reframe of `memberPackages`). RESTRICT on delete protects the
     * financial record (§5.4).
     *
     * N+1: the member dashboard renders active lots — eager-load with
     * `Member::with('creditLots')` (scope with `->active()`).
     *
     * @return HasMany<CreditLot, $this>
     */
    public function creditLots(): HasMany
    {
        return $this->hasMany(CreditLot::class);
    }

    /**
     * Member-level money-ledger movements (denormalized `member_id` for statements
     * without a join). Serves the wallet activity feed via
     * idx_credit_ledger_member_created `(member_id, created_at)`.
     *
     * @return HasMany<CreditLedger, $this>
     */
    public function creditLedgerEntries(): HasMany
    {
        return $this->hasMany(CreditLedger::class);
    }
}
