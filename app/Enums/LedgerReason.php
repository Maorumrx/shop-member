<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Movement classification for `entitlement_ledger.reason`
 * (architecture.md §3.8, §5.2). The ledger is the append-only source of truth;
 * `qty_remaining` is `qty_total + Σ delta`.
 *
 * Sign convention for `delta`:
 * - purchase: +qty   (initial grant at sale)
 * - redeem:   -qty   (consumption at the counter)
 * - expire:   -qty   (daily job zeroes a dated lot still holding qty)
 * - refund:   +qty   (positive correction on a still-active entitlement)
 * - adjust:   ±qty   (manual correction; `note` carries the reason)
 */
enum LedgerReason: string
{
    case Purchase = 'purchase';
    case Redeem = 'redeem';
    case Expire = 'expire';
    case Refund = 'refund';
    case Adjust = 'adjust';
}
