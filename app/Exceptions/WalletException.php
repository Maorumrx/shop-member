<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

/**
 * Raised by {@see \App\Services\Wallet\WalletService} for wallet operations that
 * are ILL-FORMED or violate a money rule OTHER than a plain shortfall (a shortfall
 * on debit is {@see InsufficientCreditException}). A DOMAIN exception: the request
 * shape has passed its FormRequest, but a money-authority invariant fails, so the
 * whole transaction aborts before any ledger row is written.
 *
 * Every amount carried here is a decimal(10,2) STRING (architecture.md §5.6) — the
 * service never casts money to float.
 *
 * @see \App\Services\Wallet\WalletService
 */
final class WalletException extends RuntimeException
{
    /**
     * A top-up / debit / refund amount was not strictly positive. Money movements
     * must move a positive baht figure; zero or negative is a caller bug or a
     * hostile request that slipped validation.
     */
    public static function nonPositiveAmount(string $amount): self
    {
        return new self("Wallet amount must be positive; got [{$amount}].");
    }

    /**
     * A top-up carried no value at all (both paid and bonus were zero). A lot with
     * zero total would be an un-spendable orphan, so the sale is rejected.
     */
    public static function emptyTopUp(): self
    {
        return new self('A top-up must add a positive amount (paid and/or bonus).');
    }

    /**
     * A top-up component (amount_paid or bonus_amount) was negative. Both are
     * clamped `>= 0` at the DB (chk_credit_lots_amounts) and here so a negative can
     * never enter a lot.
     */
    public static function negativeComponent(string $component, string $value): self
    {
        return new self("Top-up {$component} cannot be negative; got [{$value}].");
    }

    /**
     * No ACTIVE {@see \App\Models\Service} price exists for the item being charged.
     * The debit path needs a canonical price to subtract; without one the charge is
     * refused (staff must price the service first) rather than debiting an
     * arbitrary amount.
     */
    public static function serviceNotPriced(string $itemCode): self
    {
        return new self("No active service price for [{$itemCode}].");
    }

    /**
     * A refund asked for more than the member's available PAID balance. A refund
     * reverses PAID value only (never bonus), so it can never exceed the sum of
     * `paid_remaining` across active lots. Nothing is written.
     *
     * @param  string  $requested      Baht the refund asked for.
     * @param  string  $availablePaid  Total refundable (paid) balance the member holds.
     */
    public static function refundExceedsPaid(string $requested, string $availablePaid): self
    {
        return new self(
            "Refund of {$requested} exceeds refundable paid balance of {$availablePaid}."
        );
    }

    /**
     * An adjustment delta was zero — a no-op correction. The owner must move a
     * non-zero (positive or negative) amount.
     */
    public static function zeroAdjustment(): self
    {
        return new self('Adjustment delta must be non-zero.');
    }

    /**
     * DEFENSIVE: a walk finished without fully applying the requested amount despite
     * the pre-flight sufficiency check passing — an impossible state that would mean
     * the ledger and lot caches have desynced. Surfaced (rolling the txn back)
     * rather than silently committing a broken balance.
     */
    public static function invariantViolation(string $detail): self
    {
        return new self("Wallet invariant violation: {$detail}.");
    }
}
