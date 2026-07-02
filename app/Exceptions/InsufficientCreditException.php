<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

/**
 * Raised by {@see \App\Services\Wallet\WalletService} when a wallet DEBIT cannot be
 * honoured because the member's spendable balance is below the requested amount.
 * The debit path CONSUMES the append-only `credit_ledger` (the single source of
 * truth), so a request that cannot be fully satisfied must abort the WHOLE
 * transaction and write ZERO rows — never a partial deduction.
 *
 * This is a DOMAIN exception, distinct from a validation error: the request has
 * already passed its FormRequest (a valid item / positive amount, an active
 * member). Reaching this guard means the member simply does not hold enough
 * spendable credit across their active, non-expired lots (FIFO). The
 * {@see \App\Services\Wallet\WalletService::debit()} throws BEFORE decrementing
 * anything and the caller turns it into a clean "เครดิตไม่พอ" error toast rather
 * than a 500. Booking check-in catches it by letting the transaction roll back so
 * the booking stays `confirmed`.
 *
 * @see \App\Services\Wallet\WalletService::debit()
 * @see \App\Services\Wallet\WalletService::chargeService()
 * @see \App\Services\Booking\BookingService::checkIn()
 */
final class InsufficientCreditException extends RuntimeException
{
    /**
     * The member holds less spendable credit than the amount requested.
     *
     * "Spendable" is the FIFO-eligible set at debit time: active, non-expired lots
     * whose `paid_remaining + bonus_remaining > 0`. The transaction has not written
     * any ledger row when this is thrown — the debit is rejected atomically.
     *
     * @param  string  $requested  The baht amount the debit asked for (decimal-2 string).
     * @param  string  $available  Total spendable balance the member currently holds
     *                             (decimal-2 string).
     */
    public static function insufficient(string $requested, string $available): self
    {
        return new self(
            "Insufficient credit: requested {$requested}, only {$available} spendable."
        );
    }
}
