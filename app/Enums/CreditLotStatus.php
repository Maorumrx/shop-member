<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Lifecycle vocabulary for `credit_lots.status` — the money-wallet reframe of the
 * dropped EntitlementStatus (same three-state shape, same terminality).
 *
 *     active ──(paid_remaining + bonus_remaining == 0)──► used_up   (terminal)
 *        │
 *        └──────────(expires_at <= now, if dated)───────► expired    (terminal)
 *
 * - active:  spendable. The debit walk (FIFO) only consumes `active`, non-expired lots.
 * - used_up: the lot's total remaining (paid_remaining + bonus_remaining) reached 0
 *            via debits/refund. Terminal — a later refund/adjust appends a NEW ledger
 *            row on a still-active lot, it never resurrects a closed one.
 * - expired: a DATED lot (`expires_at IS NOT NULL`) passed its expiry while still
 *            holding remaining value. The (currently-off) daily expiry job writes a
 *            `credit_ledger` row (reason=expire, delta = -(total remaining)) then
 *            zeroes both remainings. Terminal. NEVER reached while expiry stays off
 *            (all lots ship with `expires_at = null` until the client enables it).
 */
enum CreditLotStatus: string
{
    case Active = 'active';
    case UsedUp = 'used_up';
    case Expired = 'expired';
}
