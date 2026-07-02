<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Movement classification for `credit_ledger.reason` — the money-wallet reframe of
 * the dropped LedgerReason. The ledger is the append-only source of truth;
 * a member's spendable balance == SUM of active lot remainings == the latest
 * row's `balance_after`.
 *
 * `delta` is SIGNED baht (decimal(10,2), never float, §5.6). Sign convention:
 * - topup:  +amount_paid   paid portion of a top-up lot enters the wallet.
 * - bonus:  +bonus_amount  promotional portion of that same lot (a SEPARATE row so
 *                          paid vs bonus stay auditable end-to-end; refunds return
 *                          only paid). Omitted when the lot has no bonus.
 * - debit:  -price         one row per lot the service visit consumes (bonus_remaining
 *                          spent before paid_remaining WITHIN each lot); booking_id set
 *                          when the debit is a booking check-in.
 * - refund: -amount        reverses PAID value only (never bonus) back out of the wallet.
 * - expire: -remaining     the (currently-off) expiry job zeroes a dated lot still
 *                          holding value.
 * - adjust: ±amount        manual owner correction; `note` carries the reason.
 */
enum CreditLedgerReason: string
{
    case Topup = 'topup';
    case Bonus = 'bonus';
    case Debit = 'debit';
    case Refund = 'refund';
    case Expire = 'expire';
    case Adjust = 'adjust';
}
