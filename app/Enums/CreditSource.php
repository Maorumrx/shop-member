<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Origin classification for a `credit_lots` row — how the stored-value lot came
 * into existence (the money-wallet reframe of the dropped package model).
 *
 * A lot is the unit of paid/bonus tracking and (optional) per-lot expiry; it is
 * created either by a paying top-up or by a manual owner adjustment. String-backed,
 * same style as the dropped LedgerReason / EntitlementStatus.
 *
 * - topup:      customer paid real baht at the counter/POS and (optionally) got a
 *               promotional bonus — the normal sell path (amount_paid > 0).
 * - adjustment: owner-granted credit with no matching cash sale (goodwill / manual
 *               correction / opening balance). Typically amount_paid = 0 and the
 *               whole lot is bonus, but either column may carry value; the reason
 *               is carried on the paired `credit_ledger` row's `note`.
 */
enum CreditSource: string
{
    case Topup = 'topup';
    case Adjustment = 'adjustment';
}
