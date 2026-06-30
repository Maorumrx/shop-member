<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

/**
 * Raised by {@see \App\Services\Redemption\RedemptionService} when a redemption
 * cannot be honoured. This is the REVENUE-CORE guard: redemption CONSUMES the
 * append-only entitlement ledger (architecture.md §5.2, §6.3), so a request that
 * cannot be fully satisfied must abort the whole transaction and write ZERO rows
 * — never a partial deduction (§5.2 ledger-as-truth invariant).
 *
 * This is a DOMAIN exception, distinct from a validation error: the request has
 * already passed {@see \App\Http\Requests\Admin\StoreRedemptionRequest} (a valid
 * `item_code` + positive `qty`, an active member). Reaching this guard means the
 * member simply does not hold enough redeemable units of the item — at the chosen
 * branch, not yet expired, FIFO across lots — so the {@see RedemptionService}
 * throws BEFORE decrementing anything and the {@see \App\Http\Controllers\Admin\RedemptionController}
 * turns it into a clean "สิทธิ์ไม่พอ" error toast rather than a 500.
 *
 * @see \App\Services\Redemption\RedemptionService::redeem()
 */
final class RedemptionException extends RuntimeException
{
    /**
     * The member holds fewer redeemable units of `$itemCode` than requested.
     *
     * "Redeemable" is the FIFO-eligible set at redemption time (§6.3): active,
     * `qty_remaining > 0`, not expired, and branch-eligible. The transaction has
     * not written any ledger row when this is thrown — the redemption is rejected
     * atomically (§5.2).
     *
     * @param  string  $itemCode   The requested item code (e.g. `MASSAGE_60`).
     * @param  int     $available  Total redeemable units the member currently holds.
     * @param  int     $requested  Units the operator asked to redeem.
     */
    public static function insufficient(string $itemCode, int $available, int $requested): self
    {
        return new self(
            "Insufficient entitlement for [{$itemCode}]: requested {$requested}, only {$available} redeemable."
        );
    }

    /**
     * The member holds NOTHING redeemable for `$itemCode` at all (zero eligible
     * lots). A special case of {@see self::insufficient()} (available = 0) kept
     * separate so the UI / logs can distinguish "ran out" from "never had any"
     * (e.g. wrong branch, all expired, or never purchased). Nothing is written.
     *
     * @param  string  $itemCode  The requested item code.
     */
    public static function nothingRedeemable(string $itemCode): self
    {
        return new self("No redeemable entitlement for [{$itemCode}] (none active/eligible at this branch).");
    }
}
