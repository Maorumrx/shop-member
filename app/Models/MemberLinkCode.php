<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * MemberLinkCode (docs/member-line-linking-design.md §2) — a short-lived,
 * staff-generated claim code that lets a customer attach their LINE account to an
 * existing counter {@see Member} on first LINE login.
 *
 * The code is persisted ONLY as a SHA-256 hex hash (`code_hash`); the plaintext 6
 * digits are returned once at generation and never stored (§3). Lifecycle is
 * modelled by timestamps rather than a status enum (matching the bookings
 * "creation IS confirmation" style): a row is LIVE while
 * `consumed_at IS NULL AND expires_at > now()`, and DEAD once `consumed_at` is set
 * (used, superseded by a regenerate, or burned at 5 attempts). No SoftDeletes —
 * this is a credential/audit log, retained but not member/financial data.
 *
 * All lifecycle mutation goes through {@see \App\Services\Line\MemberLinkService}
 * (generate/claim, transaction + member row lock). This model is a thin data
 * carrier; nothing here should mint, consume, or validate a code on its own.
 *
 * @property int $id
 * @property int $member_id
 * @property string $code_hash
 * @property \Illuminate\Support\Carbon $expires_at
 * @property int $attempts
 * @property \Illuminate\Support\Carbon|null $consumed_at
 * @property string|null $consumed_by_line_user_id
 * @property int|null $created_by_user_id
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 */
class MemberLinkCode extends Model
{
    /**
     * @var list<string>
     */
    protected $fillable = [
        'member_id',
        'code_hash',
        'expires_at',
        'attempts',
        'consumed_at',
        'consumed_by_line_user_id',
        'created_by_user_id',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'member_id' => 'integer',
            'attempts' => 'integer',
            'created_by_user_id' => 'integer',
            'expires_at' => 'datetime',
            'consumed_at' => 'datetime',
        ];
    }

    /**
     * The counter member this code claims. RESTRICT-protected at the DB level
     * (members are soft-deleted only, §5.4).
     *
     * @return BelongsTo<Member, $this>
     */
    public function member(): BelongsTo
    {
        return $this->belongsTo(Member::class);
    }
}
