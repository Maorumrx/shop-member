<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Catalog line / entitlement item classification
 * (architecture.md §3.5, §3.7).
 *
 * - service: a main redeemable item.
 * - addon:   an extra; may be bound to a service via `redeem_group` (§5.3).
 *
 * Snapshotted from `package_lines.item_type` onto `entitlements.item_type`
 * at purchase — never re-read from the catalog (§5.1).
 */
enum ItemType: string
{
    case Service = 'service';
    case Addon = 'addon';
}
