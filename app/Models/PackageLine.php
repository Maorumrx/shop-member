<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\ItemType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * PackageLine (architecture.md §3.5) — one catalog item within a package
 * (a service or an add-on) plus its add-on coupling label. `redeem_group` null
 * = independent line; a shared non-null value binds lines that must redeem
 * together (§5.3). Lines belong wholly to the package (CASCADE on delete); all
 * descriptor values are snapshotted onto `entitlements` at purchase (§5.1).
 *
 * @property int $id
 * @property int $package_id
 * @property string $item_code
 * @property string $item_name
 * @property ItemType $item_type
 * @property int $qty
 * @property string|null $redeem_group
 */
class PackageLine extends Model
{
    /**
     * @var list<string>
     */
    protected $fillable = [
        'package_id',
        'item_code',
        'item_name',
        'item_type',
        'qty',
        'redeem_group',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'package_id' => 'integer',
            'item_type' => ItemType::class,
            'qty' => 'integer',
        ];
    }

    /**
     * Owner package definition.
     *
     * @return BelongsTo<Package, $this>
     */
    public function package(): BelongsTo
    {
        return $this->belongsTo(Package::class);
    }
}
