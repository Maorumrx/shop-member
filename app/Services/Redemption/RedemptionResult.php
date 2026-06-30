<?php

declare(strict_types=1);

namespace App\Services\Redemption;

/**
 * Immutable outcome of one successful {@see RedemptionService::redeem()} call: the
 * ordered list of {@see RedemptionMovement}s (one per decremented entitlement —
 * the FIFO-consumed primaries AND any coupled `redeem_group` siblings, in the
 * order they were applied), plus the originally-requested item + qty for context.
 *
 * The redemption is atomic (architecture.md §6.3): if this object exists, every
 * movement it lists committed together with its ledger row. The controller may
 * stash {@see self::toArray()} in the success flash so the member Show page can
 * render exactly what was deducted ("ตัดนวด 1 (จากล็อตหมด X) เหลือ Y; ประคบ 1").
 */
final readonly class RedemptionResult
{
    /**
     * @param  string                    $itemCode   The directly-requested item code.
     * @param  int                       $qty        The directly-requested quantity.
     * @param  list<RedemptionMovement>  $movements  Each decrement, in apply order.
     */
    public function __construct(
        public string $itemCode,
        public int $qty,
        public array $movements,
    ) {
    }

    /**
     * Total units deducted across the directly-requested item (excludes coupled
     * siblings, whose codes differ). For a well-formed redemption this equals the
     * requested `$qty`.
     */
    public function totalTakenForRequestedItem(): int
    {
        // Capture into a local: a `static` closure can't reference $this.
        $itemCode = $this->itemCode;

        return array_sum(
            array_map(
                static fn (RedemptionMovement $m): int => $m->itemCode === $itemCode ? $m->taken : 0,
                $this->movements,
            )
        );
    }

    /**
     * Flatten for the Inertia flash payload.
     *
     * @return array{
     *     item_code: string,
     *     qty: int,
     *     movements: list<array<string, mixed>>
     * }
     */
    public function toArray(): array
    {
        return [
            'item_code' => $this->itemCode,
            'qty' => $this->qty,
            'movements' => array_map(
                static fn (RedemptionMovement $m): array => $m->toArray(),
                $this->movements,
            ),
        ];
    }
}
