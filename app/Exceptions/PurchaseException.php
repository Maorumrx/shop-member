<?php

declare(strict_types=1);

namespace App\Exceptions;

use App\Models\Package;
use RuntimeException;

/**
 * Raised by {@see \App\Services\Purchase\PurchaseService} when a package cannot
 * be sold: it is inactive (hidden from sale) or it has no lines (nothing to
 * grant). Selling such a package would mint either zero entitlements or a lot a
 * customer can't redeem, so the whole purchase transaction is aborted before any
 * ledger row is written (architecture.md §3.4, §5.2).
 *
 * This is a DOMAIN exception, distinct from a validation error: the request
 * already passed {@see \App\Http\Requests\Admin\StorePurchaseRequest} (the
 * package exists + is active by the rule), so reaching this guard means a race
 * (e.g. the package was deactivated or emptied between validation and the
 * transaction). The PurchaseController catches it and flashes a clean error
 * toast instead of surfacing a 500.
 *
 * @see \App\Services\Purchase\PurchaseService::purchase()
 */
final class PurchaseException extends RuntimeException
{
    /**
     * The package cannot be sold because it is inactive (hidden from sale, §3.4).
     */
    public static function inactivePackage(Package $package): self
    {
        return new self("Package [{$package->id}] is inactive and cannot be sold.");
    }

    /**
     * The package cannot be sold because it has no lines — there is nothing to
     * grant, so it would mint a lot with zero entitlements (§3.5, §3.7).
     */
    public static function noLines(Package $package): self
    {
        return new self("Package [{$package->id}] has no lines and cannot be sold.");
    }
}
