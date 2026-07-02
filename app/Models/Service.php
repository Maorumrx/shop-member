<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Service — the per-service baht PRICE LIST the credit-wallet debit path consumes.
 * The debit-at-check-in flow resolves a member's `item_code` to this row's `price`,
 * then subtracts it from the wallet (credit_lots FIFO) and appends credit_ledger
 * rows. Mutable reference data: editing `price` never rewrites past debits (each
 * ledger row froze the baht taken at the time).
 *
 * `item_code` is globally UNIQUE — one canonical price per business code, shared
 * with `bookings.item_code`. `branch_id` is an optional scope hint (null =
 * any-branch price), SET NULL on branch delete.
 *
 * @property int $id
 * @property string $item_code
 * @property string $name
 * @property string $price        decimal(10,2) — kept as string for exactness (§5.6)
 * @property int|null $branch_id
 * @property bool $is_active
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 */
class Service extends Model
{
    /**
     * @var list<string>
     */
    protected $fillable = [
        'item_code',
        'name',
        'price',
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
            'branch_id' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    /**
     * Active (sellable / redeemable) services only.
     *
     * @param  Builder<Service>  $query
     * @return Builder<Service>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /**
     * Optional branch scope; null = priced at any branch (§5.5-style).
     *
     * @return BelongsTo<Branch, $this>
     */
    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }
}
