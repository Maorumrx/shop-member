<?php

declare(strict_types=1);

namespace App\Services\Wallet;

use App\Enums\CreditLedgerReason;
use App\Enums\CreditLotStatus;
use App\Enums\CreditSource;
use App\Exceptions\InsufficientCreditException;
use App\Exceptions\WalletException;
use App\Models\CreditLedger;
use App\Models\CreditLot;
use App\Models\Member;
use App\Models\Service;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * WalletService — the SINGLE money authority for the credit wallet (the money-wallet
 * reframe of the dropped PurchaseService + RedemptionService).
 *
 * EVERY spendable-balance change flows through here so the append-only
 * `credit_ledger` invariant holds at all times:
 *
 *     balance == SUM(active, non-expired credit_lots.paid_remaining + bonus_remaining)
 *             == the member's latest credit_ledger.balance_after            (>= 0)
 *
 * MONEY IS DECIMAL-2 STRINGS, NEVER FLOAT (architecture.md §5.6). All arithmetic
 * uses bcmath at scale 2 (bcadd/bcsub/bccomp) — there is not a single float/int
 * cast on a money value in this class.
 *
 * CONCURRENCY / CORRECTNESS (the critical point, mirrors RedemptionService §6.3):
 * every mutating method runs in ONE `DB::transaction` and, as its FIRST act, takes
 * `SELECT ... FROM members WHERE id = ? FOR UPDATE` — a per-member mutex that exists
 * even when the member holds ZERO lots (so two concurrent top-ups on a brand-new
 * member cannot both read balance 0). Debit/refund/adjust additionally
 * `lockForUpdate()` the FIFO lot set (defense-in-depth, and it keeps the locked
 * row-set tight via idx_credit_lots_fifo). Two cashiers debiting the last baht of
 * the same member are serialised: the second blocks until the first commits, then
 * re-reads the decremented remainings and is rejected if short. Because writes are
 * serialised per member, the running `balance_after` written on each row is always
 * the true post-row balance — the invariant can never be observed broken.
 *
 * ATOMICITY: a shortfall throws {@see InsufficientCreditException} BEFORE any write,
 * rolling back to a no-op. Any exception inside the transaction rolls back the WHOLE
 * operation — no partial deduction, no orphan ledger row.
 *
 * PAID vs BONUS (client rule): within a lot a debit consumes `bonus_remaining`
 * BEFORE `paid_remaining` (promotional value burns first); a refund reverses
 * `paid_remaining` only (bonus is never returned).
 */
final class WalletService
{
    /**
     * bcmath scale for all money math — decimal(10,2) means 2 fractional digits.
     */
    private const SCALE = 2;

    /**
     * Record a top-up: create ONE `credit_lots` row and its opening ledger row(s).
     *
     * Writes a `topup` ledger row (+amountPaid) and, only when bonus > 0, a SEPARATE
     * `bonus` ledger row (+bonusAmount) so paid vs bonus stay auditable end-to-end.
     * Each row's `balance_after` is the member balance AFTER that row applied.
     *
     * @param  string                 $amountPaid  Cash paid, decimal-2 string (e.g. "10000.00"). `>= 0`.
     * @param  string                 $bonusAmount Promotional grant, decimal-2 string. `>= 0`.
     * @param  CreditSource           $source      topup (paying sale) or adjustment.
     * @param  User|null              $staff       Acting operator → lot.created_by_user_id + ledger.staff_id.
     * @param  int|null               $branchId    Snapshot of WHERE the top-up happened (null = any-branch).
     * @param  CarbonInterface|null   $expiresAt   Per-lot expiry (null = never; capability off by default).
     *
     * @return CreditLot The created lot with its `ledgerEntries` loaded.
     *
     * @throws WalletException When a component is negative, or the lot would carry no value.
     */
    public function topUp(
        Member $member,
        string $amountPaid,
        string $bonusAmount,
        CreditSource $source,
        ?User $staff,
        ?int $branchId = null,
        ?CarbonInterface $expiresAt = null,
    ): CreditLot {
        $paid = $this->money($amountPaid);
        $bonus = $this->money($bonusAmount);

        if ($this->isNegative($paid)) {
            throw WalletException::negativeComponent('amount_paid', $paid);
        }
        if ($this->isNegative($bonus)) {
            throw WalletException::negativeComponent('bonus_amount', $bonus);
        }
        if (! $this->isPositive($paid) && ! $this->isPositive($bonus)) {
            throw WalletException::emptyTopUp();
        }

        return DB::transaction(function () use ($member, $paid, $bonus, $source, $staff, $branchId, $expiresAt): CreditLot {
            // (1) Per-member mutex — serialises with every other wallet mutation
            // (works even when the member has no lots yet).
            $this->lockMember($member);

            // (2) Balance BEFORE this top-up, summed from the locked lot set with
            // bcmath (the invariant baseline for the running balance_after).
            $balance = $this->sumRemaining($this->lockedActiveLots($member));

            // (3) The lot. Remainings start == originals; source recorded; expiry
            // optional. Money written from the validated strings, never a float.
            $lot = CreditLot::create([
                'member_id' => $member->id,
                'source' => $source,
                'amount_paid' => $paid,
                'bonus_amount' => $bonus,
                'paid_remaining' => $paid,
                'bonus_remaining' => $bonus,
                'expires_at' => $expiresAt,
                'status' => CreditLotStatus::Active,
                'purchased_at' => now(),
                'branch_id' => $branchId,
                'created_by_user_id' => $staff?->id,
            ]);

            // (4) Opening ledger rows. Paid always; bonus only when > 0. Each
            // balance_after is the running total after that row.
            if ($this->isPositive($paid)) {
                $balance = bcadd($balance, $paid, self::SCALE);
                $this->appendLedger($member, $lot->id, $paid, CreditLedgerReason::Topup, $balance, $staff);
            }

            if ($this->isPositive($bonus)) {
                $balance = bcadd($balance, $bonus, self::SCALE);
                $this->appendLedger($member, $lot->id, $bonus, CreditLedgerReason::Bonus, $balance, $staff);
            }

            return $lot->load('ledgerEntries');
        });
    }

    /**
     * Debit `$amount` from the member's wallet, FIFO across active, non-expired lots.
     *
     * Selects + LOCKS the FIFO lot set (`expires_at IS NULL OR > now()`, ordered
     * `expires_at asc NULLS LAST, purchased_at asc, id asc`). If the spendable total
     * is below `$amount`, throws {@see InsufficientCreditException} BEFORE any write.
     * Otherwise walks the lots taking `min(remaining_to_take, lot_total_remaining)`;
     * WITHIN a lot consumes `bonus_remaining` before `paid_remaining`; writes ONE
     * `credit_ledger` row per lot touched (delta negative, running balance_after,
     * booking_id/staff_id set); flips a lot to `used_up` when both remainings hit 0.
     *
     * @param  string              $amount    Baht to debit, decimal-2 string. Must be `> 0`.
     * @param  CreditLedgerReason  $reason    Movement reason (debit for a service; adjust for a negative correction).
     * @param  User|null           $staff     Acting operator → ledger.staff_id (null for system).
     * @param  int|null            $branchId  Audit/context only — the wallet is ONE fungible
     *                                        balance, so branch does NOT filter lots (see class note).
     * @param  int|null            $bookingId Booking check-in id stamped on every row it writes.
     * @param  string|null         $note      Free-text stamped on every row (used by adjust).
     *
     * @return WalletTransactionResult Touched-lot movements + final balance.
     *
     * @throws WalletException              When `$amount` is not positive.
     * @throws InsufficientCreditException  When the spendable balance is below `$amount` (no writes).
     */
    public function debit(
        Member $member,
        string $amount,
        CreditLedgerReason $reason,
        ?User $staff = null,
        ?int $branchId = null,
        ?int $bookingId = null,
        ?string $note = null,
    ): WalletTransactionResult {
        $amount = $this->money($amount);

        if (! $this->isPositive($amount)) {
            throw WalletException::nonPositiveAmount($amount);
        }

        return DB::transaction(function () use ($member, $amount, $reason, $staff, $bookingId, $note): WalletTransactionResult {
            $this->lockMember($member);

            // Lock the FIFO candidate set. This IS the whole balance set, so its sum
            // is the current spendable balance (the sufficiency baseline).
            $lots = $this->lockedActiveLots($member);
            $balanceBefore = $this->sumRemaining($lots);

            // Atomicity gate: refuse a partial debit — throw before writing anything.
            if (bccomp($balanceBefore, $amount, self::SCALE) === -1) {
                throw InsufficientCreditException::insufficient($amount, $balanceBefore);
            }

            $balance = $balanceBefore;
            $remaining = $amount;

            /** @var list<WalletMovement> $movements */
            $movements = [];

            foreach ($lots as $lot) {
                if (bccomp($remaining, '0', self::SCALE) !== 1) {
                    break; // fully applied
                }

                $lotTotal = bcadd($lot->paid_remaining, $lot->bonus_remaining, self::SCALE);
                if (! $this->isPositive($lotTotal)) {
                    continue; // defensive: skip a drained lot
                }

                // Take at most this lot's total remaining.
                $take = bccomp($remaining, $lotTotal, self::SCALE) === -1 ? $remaining : $lotTotal;

                // Consume BONUS first, then PAID.
                $bonusTake = bccomp($take, $lot->bonus_remaining, self::SCALE) === -1
                    ? $take
                    : $lot->bonus_remaining;
                $paidTake = bcsub($take, $bonusTake, self::SCALE);

                $lot->bonus_remaining = bcsub($lot->bonus_remaining, $bonusTake, self::SCALE);
                $lot->paid_remaining = bcsub($lot->paid_remaining, $paidTake, self::SCALE);
                $lotRemainingAfter = bcadd($lot->paid_remaining, $lot->bonus_remaining, self::SCALE);

                if (! $this->isPositive($lotRemainingAfter)) {
                    $lot->status = CreditLotStatus::UsedUp;
                }
                $lot->save();

                // Running balance after this row; delta is signed negative.
                $balance = bcsub($balance, $take, self::SCALE);
                $delta = bcsub('0', $take, self::SCALE);

                $this->appendLedger($member, $lot->id, $delta, $reason, $balance, $staff, $bookingId, $note);

                $movements[] = new WalletMovement(
                    creditLotId: $lot->id,
                    reason: $reason->value,
                    delta: $delta,
                    paidDelta: bcsub('0', $paidTake, self::SCALE),
                    bonusDelta: bcsub('0', $bonusTake, self::SCALE),
                    lotRemainingAfter: $lotRemainingAfter,
                    lotStatus: $lot->status->value,
                    balanceAfter: $balance,
                );

                $remaining = bcsub($remaining, $take, self::SCALE);
            }

            // Must have fully applied (guaranteed by the sufficiency gate). If not,
            // the caches desynced from the ledger — surface it, rolling back.
            if (bccomp($remaining, '0', self::SCALE) !== 0) {
                throw WalletException::invariantViolation("debit left {$remaining} unapplied after FIFO walk");
            }

            return new WalletTransactionResult(
                reason: $reason->value,
                netDelta: bcsub($balance, $balanceBefore, self::SCALE),
                balanceAfter: $balance,
                movements: $movements,
            );
        });
    }

    /**
     * Charge a member the ACTIVE catalog price of `$itemCode`, then debit it
     * (reason=debit). The check-in / admin manual-charge entry point.
     *
     * `item_code` is globally unique in `services`, so the price is one canonical
     * row regardless of branch; `$branchId` is passed through for audit context but
     * does not affect price resolution or the (single, fungible) wallet debit.
     *
     * @throws WalletException              When no ACTIVE service prices `$itemCode`.
     * @throws InsufficientCreditException  When the balance is below the price (no writes).
     */
    public function chargeService(
        Member $member,
        string $itemCode,
        ?User $staff,
        ?int $branchId,
        ?int $bookingId = null,
    ): WalletTransactionResult {
        /** @var Service|null $service */
        $service = Service::query()
            ->where('item_code', $itemCode)
            ->where('is_active', true)
            ->first();

        if ($service === null) {
            throw WalletException::serviceNotPriced($itemCode);
        }

        return $this->debit(
            member: $member,
            amount: $service->price,
            reason: CreditLedgerReason::Debit,
            staff: $staff,
            branchId: $branchId,
            bookingId: $bookingId,
            note: null,
        );
    }

    /**
     * Refund `$amount` of PAID value (never bonus) back out of the wallet.
     *
     * FIFO across active, non-expired lots — the SAME lot order as debit. Rationale:
     * Phase 2a has no per-lot picker UI, so a single deterministic algorithm keeps
     * refunds predictable and mirrors the established debit/redemption discipline
     * (oldest paid money reversed first, matching the order it would be consumed
     * last). Reduces `paid_remaining` ONLY; a lot flips to `used_up` if both
     * remainings reach 0. Writes ONE `credit_ledger` row (reason=refund) per lot
     * touched.
     *
     * @param  string       $amount Baht to refund, decimal-2 string. Must be `> 0`.
     * @param  User|null    $staff  Acting operator → ledger.staff_id.
     * @param  string       $note   Why the refund happened (stamped on every row).
     *
     * @return WalletTransactionResult Touched-lot movements + final balance.
     *
     * @throws WalletException When `$amount` is not positive, or exceeds the
     *                         refundable (paid) balance (no writes).
     */
    public function refund(Member $member, string $amount, ?User $staff, string $note): WalletTransactionResult
    {
        $amount = $this->money($amount);

        if (! $this->isPositive($amount)) {
            throw WalletException::nonPositiveAmount($amount);
        }

        return DB::transaction(function () use ($member, $amount, $staff, $note): WalletTransactionResult {
            $this->lockMember($member);

            $lots = $this->lockedActiveLots($member);
            $balanceBefore = $this->sumRemaining($lots);
            $availablePaid = $this->sumPaid($lots);

            // A refund can only reverse PAID value — cap on paid_remaining, not total.
            if (bccomp($availablePaid, $amount, self::SCALE) === -1) {
                throw WalletException::refundExceedsPaid($amount, $availablePaid);
            }

            $balance = $balanceBefore;
            $remaining = $amount;

            /** @var list<WalletMovement> $movements */
            $movements = [];

            foreach ($lots as $lot) {
                if (bccomp($remaining, '0', self::SCALE) !== 1) {
                    break;
                }

                if (! $this->isPositive($lot->paid_remaining)) {
                    continue; // nothing paid left to refund in this lot
                }

                $take = bccomp($remaining, $lot->paid_remaining, self::SCALE) === -1
                    ? $remaining
                    : $lot->paid_remaining;

                $lot->paid_remaining = bcsub($lot->paid_remaining, $take, self::SCALE);
                $lotRemainingAfter = bcadd($lot->paid_remaining, $lot->bonus_remaining, self::SCALE);

                if (! $this->isPositive($lotRemainingAfter)) {
                    $lot->status = CreditLotStatus::UsedUp;
                }
                $lot->save();

                $balance = bcsub($balance, $take, self::SCALE);
                $delta = bcsub('0', $take, self::SCALE);

                $this->appendLedger($member, $lot->id, $delta, CreditLedgerReason::Refund, $balance, $staff, null, $note);

                $movements[] = new WalletMovement(
                    creditLotId: $lot->id,
                    reason: CreditLedgerReason::Refund->value,
                    delta: $delta,
                    paidDelta: $delta,          // refund only touches paid
                    bonusDelta: '0.00',
                    lotRemainingAfter: $lotRemainingAfter,
                    lotStatus: $lot->status->value,
                    balanceAfter: $balance,
                );

                $remaining = bcsub($remaining, $take, self::SCALE);
            }

            if (bccomp($remaining, '0', self::SCALE) !== 0) {
                throw WalletException::invariantViolation("refund left {$remaining} unapplied after FIFO walk");
            }

            return new WalletTransactionResult(
                reason: CreditLedgerReason::Refund->value,
                netDelta: bcsub($balance, $balanceBefore, self::SCALE),
                balanceAfter: $balance,
                movements: $movements,
            );
        });
    }

    /**
     * Owner correction, reason=adjust. `$delta` is SIGNED:
     *   - POSITIVE: create an `adjustment`-source lot carrying the grant as BONUS
     *     (amount_paid = 0), then write one +delta ledger row. Bonus, not paid, so
     *     a goodwill grant is spent first and can NEVER be clawed back as cash by a
     *     refund (refund reverses paid only).
     *   - NEGATIVE: debit `|delta|` (reason=adjust) FIFO — rejected with
     *     {@see InsufficientCreditException} if it would drive the balance below 0.
     *
     * @param  string     $delta SIGNED decimal-2 string (e.g. "500.00" or "-50.00"). Non-zero.
     * @param  User|null  $staff Acting operator → lot.created_by_user_id / ledger.staff_id.
     * @param  string     $note  Why the adjustment happened (stamped on the row).
     *
     * @return WalletTransactionResult Movement(s) + final balance. For a positive
     *                                 adjust, `creditLotId` is the new lot.
     *
     * @throws WalletException              When `$delta` is zero.
     * @throws InsufficientCreditException  When a negative adjust would go below 0 (no writes).
     */
    public function adjust(Member $member, string $delta, ?User $staff, string $note): WalletTransactionResult
    {
        $delta = $this->money($delta);
        $sign = bccomp($delta, '0', self::SCALE);

        if ($sign === 0) {
            throw WalletException::zeroAdjustment();
        }

        // NEGATIVE: reuse the debit primitive with reason=adjust.
        if ($sign === -1) {
            $magnitude = bcsub('0', $delta, self::SCALE);

            return $this->debit(
                member: $member,
                amount: $magnitude,
                reason: CreditLedgerReason::Adjust,
                staff: $staff,
                branchId: null,
                bookingId: null,
                note: $note,
            );
        }

        // POSITIVE: mint an adjustment lot (value held as bonus) + one adjust row.
        return DB::transaction(function () use ($member, $delta, $staff, $note): WalletTransactionResult {
            $this->lockMember($member);

            $balanceBefore = $this->sumRemaining($this->lockedActiveLots($member));

            $lot = CreditLot::create([
                'member_id' => $member->id,
                'source' => CreditSource::Adjustment,
                'amount_paid' => '0.00',
                'bonus_amount' => $delta,
                'paid_remaining' => '0.00',
                'bonus_remaining' => $delta,
                'expires_at' => null,
                'status' => CreditLotStatus::Active,
                'purchased_at' => now(),
                'branch_id' => null,
                'created_by_user_id' => $staff?->id,
            ]);

            $balance = bcadd($balanceBefore, $delta, self::SCALE);

            $this->appendLedger($member, $lot->id, $delta, CreditLedgerReason::Adjust, $balance, $staff, null, $note);

            $movement = new WalletMovement(
                creditLotId: $lot->id,
                reason: CreditLedgerReason::Adjust->value,
                delta: $delta,
                paidDelta: '0.00',
                bonusDelta: $delta,
                lotRemainingAfter: $delta,
                lotStatus: CreditLotStatus::Active->value,
                balanceAfter: $balance,
            );

            return new WalletTransactionResult(
                reason: CreditLedgerReason::Adjust->value,
                netDelta: $delta,
                balanceAfter: $balance,
                movements: [$movement],
                creditLotId: $lot->id,
            );
        });
    }

    /**
     * The member's spendable balance: SUM(paid_remaining + bonus_remaining) over
     * ACTIVE, non-expired lots, as a decimal-2 STRING. A pure read (no lock) — the
     * one canonical balance definition the read-model and the mutating gates share.
     * Returns "0.00" for a member with nothing spendable.
     */
    public function balance(Member $member): string
    {
        $sum = $this->activeLotsQuery($member)
            ->selectRaw('COALESCE(SUM(paid_remaining + bonus_remaining), 0) AS bal')
            ->value('bal');

        // Normalise: MySQL returns a decimal string (exact); sqlite may return a
        // float — bcadd against "0" coerces to a clean 2-dp string either way.
        return bcadd((string) ($sum ?? '0'), '0', self::SCALE);
    }

    // ---------------------------------------------------------------------------
    // Internals
    // ---------------------------------------------------------------------------

    /**
     * Per-member mutex: `SELECT ... FROM members WHERE id = ? FOR UPDATE`. The FIRST
     * act of every mutating method. Serialises ALL wallet mutations for the member —
     * critically, even when they hold ZERO lots (so concurrent first top-ups can't
     * both read balance 0). Held until the surrounding transaction commits.
     */
    private function lockMember(Member $member): void
    {
        Member::query()
            ->whereKey($member->id)
            ->lockForUpdate()
            ->firstOrFail();
    }

    /**
     * The member's FIFO-ordered, LOCKED spendable lots: active, non-expired, ordered
     * `expires_at asc NULLS LAST, purchased_at asc, id asc` (rides idx_credit_lots_fifo).
     * The lock is defense-in-depth on top of the member mutex and keeps the touched
     * row-set tight. Returned in the exact order debit/refund consume them.
     *
     * @return Collection<int, CreditLot>
     */
    private function lockedActiveLots(Member $member): Collection
    {
        return $this->activeLotsQuery($member)
            // NULLS LAST: `expires_at IS NULL` sorts 0 (dated) before 1 (never), so
            // dated lots go first, never-expiring last.
            ->orderByRaw('expires_at IS NULL asc')
            ->orderBy('expires_at')
            ->orderBy('purchased_at')
            ->orderBy('id')
            ->lockForUpdate()
            ->get();
    }

    /**
     * Base query for a member's spendable lots: active AND (never-expires OR not yet
     * expired). The single definition shared by balance() and the FIFO walk so the
     * sufficiency gate and the balance figure can never disagree.
     *
     * @return Builder<CreditLot>
     */
    private function activeLotsQuery(Member $member): Builder
    {
        return CreditLot::query()
            ->where('member_id', $member->id)
            ->where('status', CreditLotStatus::Active)
            ->where(function (Builder $q): void {
                $q->whereNull('expires_at')
                    ->orWhere('expires_at', '>', now());
            });
    }

    /**
     * bcmath SUM of (paid_remaining + bonus_remaining) over a lot collection.
     *
     * @param  Collection<int, CreditLot>  $lots
     */
    private function sumRemaining(Collection $lots): string
    {
        $sum = '0.00';
        foreach ($lots as $lot) {
            $sum = bcadd($sum, bcadd($lot->paid_remaining, $lot->bonus_remaining, self::SCALE), self::SCALE);
        }

        return $sum;
    }

    /**
     * bcmath SUM of paid_remaining ONLY over a lot collection (refundable balance).
     *
     * @param  Collection<int, CreditLot>  $lots
     */
    private function sumPaid(Collection $lots): string
    {
        $sum = '0.00';
        foreach ($lots as $lot) {
            $sum = bcadd($sum, $this->money($lot->paid_remaining), self::SCALE);
        }

        return $sum;
    }

    /**
     * Append exactly one immutable `credit_ledger` row — the ONLY write allowed
     * against the ledger (the model forbids update/delete). `created_at` is set
     * explicitly because the table has no `updated_at` and Eloquent's timestamp
     * path would otherwise skip it.
     */
    private function appendLedger(
        Member $member,
        int $creditLotId,
        string $delta,
        CreditLedgerReason $reason,
        string $balanceAfter,
        ?User $staff,
        ?int $bookingId = null,
        ?string $note = null,
    ): void {
        CreditLedger::create([
            'member_id' => $member->id,
            'credit_lot_id' => $creditLotId,
            'delta' => $delta,
            'reason' => $reason,
            'balance_after' => $balanceAfter,
            'booking_id' => $bookingId,
            'staff_id' => $staff?->id,
            'note' => $note,
            'created_at' => now(),
        ]);
    }

    /**
     * Normalise any money input to a clean decimal-2 STRING (e.g. "300" → "300.00"),
     * so scale is uniform for comparison and storage. Never a float cast.
     */
    private function money(string $value): string
    {
        return bcadd($value, '0', self::SCALE);
    }

    /**
     * True when `$value` is strictly greater than 0 (decimal-2 comparison).
     */
    private function isPositive(string $value): bool
    {
        return bccomp($value, '0', self::SCALE) === 1;
    }

    /**
     * True when `$value` is strictly less than 0 (decimal-2 comparison).
     */
    private function isNegative(string $value): bool
    {
        return bccomp($value, '0', self::SCALE) === -1;
    }
}
