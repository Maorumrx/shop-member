<?php

declare(strict_types=1);

namespace App\Services\Wallet;

/**
 * One immutable line of a wallet money movement: a single `credit_lots` row that
 * was touched during a {@see WalletService} debit / refund / adjust, mapping 1:1 to
 * exactly one `credit_ledger` row written in the same transaction. This is the
 * money-wallet reframe of the dropped RedemptionMovement.
 *
 * The UI consumes these to show precisely what moved, e.g. "ตัด 300 จากล็อต #12
 * (โบนัส 200 + จ่าย 100) เหลือในล็อต 0". Every money field is a decimal(10,2)
 * STRING (architecture.md §5.6) — never a float. `delta`, `paidDelta`, `bonusDelta`
 * are SIGNED (negative for a debit/refund, positive for an adjust-credit line).
 */
final readonly class WalletMovement
{
    public function __construct(
        /** The `credit_lots` row this movement touched. */
        public int $creditLotId,
        /** The `credit_ledger.reason` value written for this movement. */
        public string $reason,
        /** Signed total moved on this lot (e.g. "-300.00"); == paidDelta + bonusDelta. */
        public string $delta,
        /** Signed portion applied to `paid_remaining` (e.g. "-100.00"). */
        public string $paidDelta,
        /** Signed portion applied to `bonus_remaining` (e.g. "-200.00"). */
        public string $bonusDelta,
        /** The lot's `paid_remaining + bonus_remaining` AFTER this movement. */
        public string $lotRemainingAfter,
        /** The lot's `status` AFTER this movement (active | used_up). */
        public string $lotStatus,
        /** Member TOTAL wallet balance AFTER this movement's ledger row (== balance_after). */
        public string $balanceAfter,
    ) {
    }

    /**
     * Flatten to a primitive array for an Inertia flash / JSON payload. All money
     * stays a STRING so the frontend formats it without float drift.
     *
     * @return array{
     *     credit_lot_id: int,
     *     reason: string,
     *     delta: string,
     *     paid_delta: string,
     *     bonus_delta: string,
     *     lot_remaining_after: string,
     *     lot_status: string,
     *     balance_after: string
     * }
     */
    public function toArray(): array
    {
        return [
            'credit_lot_id' => $this->creditLotId,
            'reason' => $this->reason,
            'delta' => $this->delta,
            'paid_delta' => $this->paidDelta,
            'bonus_delta' => $this->bonusDelta,
            'lot_remaining_after' => $this->lotRemainingAfter,
            'lot_status' => $this->lotStatus,
            'balance_after' => $this->balanceAfter,
        ];
    }
}
