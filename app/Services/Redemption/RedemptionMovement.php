<?php

declare(strict_types=1);

namespace App\Services\Redemption;

use Carbon\CarbonInterface;

/**
 * One immutable line of a redemption outcome: a single entitlement that was
 * decremented during {@see RedemptionService::redeem()}. Each movement maps 1:1
 * to exactly one `entitlement_ledger` row (reason=redeem) written in the same
 * transaction (architecture.md §6.3) — both the primary FIFO consumption and any
 * coupled `redeem_group` sibling produce their own movement.
 *
 * The UI consumes these to show precisely what was deducted, e.g.
 * "ตัดนวด 1 (จากล็อตหมด X) เหลือ Y; ประคบ 1" — so it carries the per-lot
 * remaining-after and whether the line was a coupled add-on.
 */
final readonly class RedemptionMovement
{
    public function __construct(
        /** Snapshot item code of the decremented entitlement (e.g. `MASSAGE_60`). */
        public string $itemCode,
        /** Snapshot human label (e.g. "นวด 60 นาที"). */
        public string $itemName,
        /** Owning lot (`member_packages.id`) — lets the UI group/identify the lot. */
        public int $memberPackageId,
        /**
         * Lot/entitlement expiry snapshot; null = never expires.
         * Typed as CarbonInterface because the app uses CarbonImmutable
         * (Date::use) — which is NOT an instance of Illuminate\Support\Carbon.
         */
        public ?CarbonInterface $expiresAt,
        /** Units taken from THIS entitlement in this redemption (a positive int). */
        public int $taken,
        /** `qty_remaining` AFTER the decrement (== the ledger row's balance_after). */
        public int $remainingAfter,
        /**
         * True when this line is a `redeem_group` SIBLING pulled along by the
         * primary consumption (best-effort coupling, §5.3, §6.3) rather than the
         * directly-requested item.
         */
        public bool $wasCoupled,
    ) {
    }

    /**
     * Flatten to a primitive array for the Inertia flash payload, so the Show
     * page can render exactly what was deducted without re-querying.
     *
     * @return array{
     *     item_code: string,
     *     item_name: string,
     *     member_package_id: int,
     *     expires_at: string|null,
     *     taken: int,
     *     remaining_after: int,
     *     was_coupled: bool
     * }
     */
    public function toArray(): array
    {
        return [
            'item_code' => $this->itemCode,
            'item_name' => $this->itemName,
            'member_package_id' => $this->memberPackageId,
            // ISO-8601 string (or null) — Inertia/Vue formats it client-side.
            'expires_at' => $this->expiresAt?->toIso8601String(),
            'taken' => $this->taken,
            'remaining_after' => $this->remainingAfter,
            'was_coupled' => $this->wasCoupled,
        ];
    }
}
