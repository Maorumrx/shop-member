<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * TopupOffer — a preset button for the top-up sell screen: "pay `amount` → get
 * `amount + bonus` spendable" (e.g. 10,000 → +1,000; 5,000 → +500). Mutable config;
 * changing an offer never touches already-sold `credit_lots` (a lot snapshots its
 * amounts at sale). A custom amount + bonus is entered at the POS and is NOT stored
 * here — this is only the quick-pick list.
 *
 * @property int $id
 * @property string $name
 * @property string $amount   decimal(10,2) — cash paid; kept as string (§5.6)
 * @property string $bonus    decimal(10,2) — promotional bonus; kept as string (§5.6)
 * @property bool $is_active
 * @property int $sort_order
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 */
class TopupOffer extends Model
{
    /**
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'amount',
        'bonus',
        'is_active',
        'sort_order',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'bonus' => 'decimal:2',
            'is_active' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    /**
     * Active presets in display order — the sell-screen list (rides
     * idx_topup_offers_active_sort).
     *
     * @param  Builder<TopupOffer>  $query
     * @return Builder<TopupOffer>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true)->orderBy('sort_order');
    }
}
