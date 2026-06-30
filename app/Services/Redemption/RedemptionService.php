<?php

declare(strict_types=1);

namespace App\Services\Redemption;

use App\Enums\EntitlementStatus;
use App\Enums\ItemType;
use App\Enums\LedgerReason;
use App\Exceptions\RedemptionException;
use App\Models\Entitlement;
use App\Models\Member;
use App\Models\MemberPackage;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * RedemptionService — the REVENUE CORE (architecture.md §3.7–§3.8, §5.2, §5.3,
 * §5.5, §5.7, and the §6.3 locking/transaction reference).
 *
 * Redemption CONSUMES the append-only entitlement ledger that PurchaseService
 * minted. One redeem of `item_code × qty` walks the member's redeemable lots in
 * FIFO order, decrements `qty_remaining`, and appends exactly ONE
 * `entitlement_ledger` row (reason=redeem) per touched entitlement — preserving
 * the §5.2 invariant `qty_remaining == qty_total + Σ delta == latest balance_after`.
 *
 * CONCURRENCY / DOUBLE-SPEND SAFETY (non-negotiable, §6.3): the redeemable set is
 * `->lockForUpdate()` inside a single `DB::transaction`. Two cashiers redeeming
 * the last unit of the same lot are serialized — the second blocks until the
 * first commits, then re-reads the now-zero remaining and is rejected. Index I1
 * keeps the locked row-set tight.
 *
 * ATOMICITY: if `sum(qty_remaining) < qty` the service throws
 * {@see RedemptionException} BEFORE any decrement, rolling back to a no-op. Any
 * exception inside the transaction rolls back the WHOLE redemption — there is no
 * partial deduction and no orphan ledger row.
 *
 * INVARIANTS enforced here:
 *   - every decrement writes EXACTLY one ledger row (delta = -taken, reason=redeem);
 *   - that row's `balance_after == qty_remaining` AFTER the decrement;
 *   - the ledger is INSERT-only (the model forbids update/delete, §3.8);
 *   - an entitlement flips to `used_up` the moment its `qty_remaining` hits 0 (§5.7);
 *   - a lot flips to `used_up` when ALL its entitlements are `used_up` (§5.7 rollup).
 *
 * Money is irrelevant to redemption — it moves quantities only.
 */
final class RedemptionService
{
    /**
     * Redeem `$qty` units of `$itemCode` for `$member`, performed by `$staff` at
     * `$branchId`, consuming the member's redeemable lots FIFO (architecture.md
     * §6.3). Atomic: either the full quantity is deducted (with coupled siblings)
     * or nothing is — a shortfall throws and rolls back.
     *
     * Branch eligibility (§5.5):
     *   - `$branchId !== null` (a branch-scoped STAFF): only lots whose snapshotted
     *     `member_packages.branch_id` is NULL (any-branch) or equals `$branchId`.
     *   - `$branchId === null` (an OWNER, unscoped): NO branch filter — the owner
     *     may redeem any lot regardless of its branch scope.
     *
     * @param  Member       $member    The redeeming customer.
     * @param  string       $itemCode  Snapshot item code to consume (e.g. `MASSAGE_60`).
     * @param  int          $qty       Units to redeem (caller-validated `>= 1`).
     * @param  User         $staff     Acting operator → `entitlement_ledger.staff_id`.
     * @param  int|null     $branchId  Redeeming staff's home branch; null = owner/unscoped.
     *
     * @return RedemptionResult  The ordered list of decrements (primaries + coupled
     *                           siblings) for the UI to render exactly what was deducted.
     *
     * @throws RedemptionException  When the redeemable balance is below `$qty`
     *                              (nothing is written — the txn rolls back).
     */
    public function redeem(Member $member, string $itemCode, int $qty, User $staff, ?int $branchId): RedemptionResult
    {
        return DB::transaction(function () use ($member, $itemCode, $qty, $staff, $branchId): RedemptionResult {
            // (1) Select + LOCK the member's redeemable lots for this item, FIFO.
            //
            // Filters mirror the §6.3 reference: active, qty_remaining>0, not
            // expired, branch-eligible. lockForUpdate() (rides I1) serializes
            // concurrent redemptions of the same rows so the last unit can't be
            // double-spent — the second transaction blocks here until the first
            // commits, then sees the decremented remaining.
            $rows = Entitlement::query()
                ->where('member_id', $member->id)
                ->where('item_code', $itemCode)
                ->where('status', EntitlementStatus::Active)
                ->where('qty_remaining', '>', 0)
                ->where(function (Builder $q): void {
                    $q->whereNull('expires_at')
                        ->orWhere('expires_at', '>', now());
                })
                // Branch eligibility (§5.5): filter by the lot's snapshotted scope
                // ONLY for a branch-scoped staff. An owner ($branchId === null) is
                // unscoped — apply NO branch filter so any lot is redeemable.
                ->when($branchId !== null, function (Builder $q) use ($branchId): void {
                    $q->whereHas('memberPackage', function (Builder $mp) use ($branchId): void {
                        $mp->whereNull('branch_id')
                            ->orWhere('branch_id', $branchId);
                    });
                })
                // FIFO: dated lots soonest-first, never-expiring (null) LAST, then
                // by id for a stable tiebreak (§6.3, the I1 ORDER BY rule §4 note).
                ->orderByRaw('expires_at IS NULL asc, expires_at asc')
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            // (2) Atomicity gate: refuse a partial redemption. If the member holds
            // fewer redeemable units than requested, throw BEFORE writing anything
            // (the §5.2 ledger-as-truth invariant — no orphan/partial movement).
            $available = (int) $rows->sum('qty_remaining');

            if ($available <= 0) {
                throw RedemptionException::nothingRedeemable($itemCode);
            }

            if ($available < $qty) {
                throw RedemptionException::insufficient($itemCode, $available, $qty);
            }

            /** @var list<RedemptionMovement> $movements */
            $movements = [];

            // Distinct lots we touch, so step (5) can roll them up to used_up.
            /** @var array<int, true> $touchedLotIds */
            $touchedLotIds = [];

            $remaining = $qty;

            // (3) Walk the LOCKED rows FIFO, consuming $qty across lots.
            foreach ($rows as $ent) {
                if ($remaining <= 0) {
                    break;
                }

                /** @var Entitlement $ent */
                $take = min($remaining, (int) $ent->qty_remaining);

                // Decrement the primary + write its one redeem ledger row.
                $movements[] = $this->applyDecrement(
                    entitlement: $ent,
                    take: $take,
                    member: $member,
                    staff: $staff,
                    wasCoupled: false,
                );

                $touchedLotIds[$ent->member_package_id] = true;
                $remaining -= $take;

                // (4) redeem_group COUPLING (best-effort, per lot, §5.3, §6.3):
                // ASYMMETRIC by design — redeeming a SERVICE pulls along its bound
                // same-lot siblings (the add-ons sharing its non-null redeem_group);
                // redeeming an add-on on its own NEVER pulls the service back. A
                // bound add-on that has run out does NOT block the primary
                // (best-effort: take min($take, sibling.qty_remaining), never throw).
                if ($ent->redeem_group !== null && $ent->item_type === ItemType::Service) {
                    foreach ($this->lockedSiblings($ent) as $sibling) {
                        /** @var Entitlement $sibling */
                        $siblingTake = min($take, (int) $sibling->qty_remaining);

                        if ($siblingTake <= 0) {
                            continue; // ran out — best-effort, skip without failing.
                        }

                        $movements[] = $this->applyDecrement(
                            entitlement: $sibling,
                            take: $siblingTake,
                            member: $member,
                            staff: $staff,
                            wasCoupled: true,
                        );

                        $touchedLotIds[$sibling->member_package_id] = true;
                    }
                }
            }

            // (5) Lot rollup (§5.7): a lot becomes used_up when ALL its
            // entitlements are used_up. Check each distinct touched lot once.
            foreach (array_keys($touchedLotIds) as $lotId) {
                $this->rollUpLot((int) $lotId);
            }

            return new RedemptionResult(
                itemCode: $itemCode,
                qty: $qty,
                movements: $movements,
            );
        });
    }

    /**
     * Decrement one (already LOCKED) entitlement by `$take` and append its single
     * redeem ledger row — the atomic unit that preserves the §5.2 invariant.
     *
     * Order matters: we mutate `qty_remaining` (and flip `used_up` when it hits 0,
     * §5.7) FIRST, then write the ledger row with `balance_after == qty_remaining`
     * AFTER the decrement. The ledger row is the ONLY write allowed against the
     * ledger (the model forbids update/delete, §3.8) — exactly one row per
     * decrement, with `delta = -$take`, `reason = redeem`, `staff_id`, and a null
     * `booking_id` (bookings are a later Phase-5 concern; §3.8).
     *
     * Pre-condition: `$take >= 1` and `$take <= $entitlement->qty_remaining` — the
     * caller (FIFO walk / coupling) guarantees this via `min(...)`, so the unsigned
     * `qty_remaining` and the §3.8 `balance_after >= 0` CHECK can never underflow.
     *
     * @param  Entitlement  $entitlement  The locked row to decrement.
     * @param  int          $take         Units to consume (>= 1, <= qty_remaining).
     * @param  Member       $member       Owner — denormalized onto the ledger row.
     * @param  User         $staff        Acting operator → ledger.staff_id.
     * @param  bool         $wasCoupled   True when pulled in as a redeem_group sibling.
     */
    private function applyDecrement(
        Entitlement $entitlement,
        int $take,
        Member $member,
        User $staff,
        bool $wasCoupled,
    ): RedemptionMovement {
        // Decrement the cache. We mutate then persist via save() (not an atomic
        // SQL decrement) deliberately: the row is lockForUpdate-held for the whole
        // transaction, so no concurrent writer can interleave (§6.3).
        $entitlement->qty_remaining = (int) $entitlement->qty_remaining - $take;

        // Terminal state when fully consumed (§5.7). Lot rollup happens in step (5).
        if ($entitlement->qty_remaining === 0) {
            $entitlement->status = EntitlementStatus::UsedUp;
        }

        $entitlement->save();

        // The MANDATORY single ledger row. balance_after snapshots qty_remaining
        // AFTER the decrement so the §5.2 chain reconciles. INSERT-only (§3.8).
        $entitlement->ledgerEntries()->create([
            'member_id' => $member->id,
            'delta' => -$take,
            'reason' => LedgerReason::Redeem,
            'balance_after' => $entitlement->qty_remaining,
            'booking_id' => null,
            'staff_id' => $staff->id,
            'note' => null,
        ]);

        return new RedemptionMovement(
            itemCode: $entitlement->item_code,
            itemName: $entitlement->item_name,
            memberPackageId: $entitlement->member_package_id,
            expiresAt: $entitlement->expires_at,
            taken: $take,
            remainingAfter: $entitlement->qty_remaining,
            wasCoupled: $wasCoupled,
        );
    }

    /**
     * Lock and return the same-lot `redeem_group` siblings of `$primary`: active,
     * still-stocked entitlements in the SAME `member_package_id`, sharing the same
     * (non-null) `redeem_group`, excluding the primary itself (§5.3 lot-scoped
     * coupling). Locked for update so the coupled decrement is double-spend-safe
     * just like the primary (§6.3).
     *
     * Returns an empty collection when the primary has no group — the caller only
     * invokes this when `redeem_group !== null`.
     *
     * @return Collection<int, Entitlement>
     */
    private function lockedSiblings(Entitlement $primary): Collection
    {
        return Entitlement::query()
            ->where('member_package_id', $primary->member_package_id)
            ->where('redeem_group', $primary->redeem_group)
            ->where('id', '!=', $primary->id)
            ->where('status', EntitlementStatus::Active)
            ->where('qty_remaining', '>', 0)
            ->orderBy('id')
            ->lockForUpdate()
            ->get();
    }

    /**
     * Lot-status rollup (§5.7): flip a lot to `used_up` once EVERY one of its
     * entitlements is `used_up`. Idempotent and cheap — a single existence check
     * for any not-yet-used_up entitlement. We only set used_up here; `expired` is
     * the daily expiry job's concern (§6.2), and a terminal lot is never resurrected.
     */
    private function rollUpLot(int $lotId): void
    {
        $hasUnclosed = Entitlement::query()
            ->where('member_package_id', $lotId)
            ->where('status', '!=', EntitlementStatus::UsedUp)
            ->exists();

        if ($hasUnclosed) {
            return;
        }

        MemberPackage::query()
            ->whereKey($lotId)
            ->where('status', EntitlementStatus::Active)
            ->update(['status' => EntitlementStatus::UsedUp]);
    }
}
