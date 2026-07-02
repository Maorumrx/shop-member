<?php

declare(strict_types=1);

namespace App\Services\Wallet;

/**
 * Immutable outcome of one successful mutating {@see WalletService} call that moves
 * spendable balance (debit / chargeService / refund / adjust). Carries the ordered
 * list of {@see WalletMovement}s (one per `credit_ledger` row written, in apply
 * order), the SIGNED net change to the wallet, and the resulting balance — the
 * money-wallet reframe of the dropped RedemptionResult.
 *
 * If this object exists, every movement it lists committed together with its ledger
 * row (the operation is atomic). `topUp()` is the exception — it returns the created
 * {@see \App\Models\CreditLot} directly per its contract, not this result.
 *
 * All money fields are decimal(10,2) STRINGS (architecture.md §5.6).
 */
final readonly class WalletTransactionResult
{
    /**
     * @param  string                $reason        Primary `credit_ledger.reason` (debit|refund|adjust).
     * @param  string                $netDelta      SIGNED net change to the wallet
     *                                              (balanceAfter − balanceBefore); negative
     *                                              for a debit/refund, positive for an
     *                                              adjust-credit.
     * @param  string                $balanceAfter  Member's spendable wallet balance after.
     * @param  list<WalletMovement>  $movements     Each touched-lot movement, in apply order.
     * @param  int|null              $creditLotId   The lot CREATED by a positive adjust
     *                                              (adjustment-source lot); null for
     *                                              debit/refund/negative-adjust.
     */
    public function __construct(
        public string $reason,
        public string $netDelta,
        public string $balanceAfter,
        public array $movements,
        public ?int $creditLotId = null,
    ) {
    }

    /**
     * Flatten for an Inertia flash / JSON payload.
     *
     * @return array{
     *     reason: string,
     *     net_delta: string,
     *     balance_after: string,
     *     credit_lot_id: int|null,
     *     movements: list<array<string, mixed>>
     * }
     */
    public function toArray(): array
    {
        return [
            'reason' => $this->reason,
            'net_delta' => $this->netDelta,
            'balance_after' => $this->balanceAfter,
            'credit_lot_id' => $this->creditLotId,
            'movements' => array_map(
                static fn (WalletMovement $m): array => $m->toArray(),
                $this->movements,
            ),
        ];
    }
}
