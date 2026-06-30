<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Shared lifecycle vocabulary for `entitlements.status` AND
 * `member_packages.status` (architecture.md §5.7). A lot's status is a rollup
 * of its entitlements.
 *
 *     active ──(all qty consumed)──► used_up   (terminal)
 *        │
 *        └────(expires_at <= now)──► expired    (terminal)
 *
 * - active:  redeemable.
 * - expired: passed `expires_at` while still holding qty. The daily expiry job
 *   writes a ledger row (reason=expire, delta = -qty_remaining) then zeroes the
 *   cache (§6.2). Terminal — no resurrection.
 * - used_up: `qty_remaining` reached 0 via redemption. Terminal.
 */
enum EntitlementStatus: string
{
    case Active = 'active';
    case Expired = 'expired';
    case UsedUp = 'used_up';
}
