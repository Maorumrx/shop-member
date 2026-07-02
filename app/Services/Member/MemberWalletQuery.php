<?php

declare(strict_types=1);

namespace App\Services\Member;

use App\Enums\CreditLotStatus;
use App\Models\CreditLedger;
use App\Models\CreditLot;
use App\Models\Member;
use App\Services\Wallet\WalletService;
use Carbon\CarbonInterface;

/**
 * MemberWalletQuery — the SINGLE source of truth for read-only projections of a
 * member's credit WALLET (the money-wallet reframe of the dropped
 * MemberEntitlementQuery). Feeds BOTH the admin member-detail page and the
 * member-facing dashboard so they render the exact same numbers.
 *
 * All money is a decimal(10,2) STRING (architecture.md §5.6) — never cast to
 * int/float. The `balance()` figure is delegated to {@see WalletService::balance()}
 * so there is ONE canonical balance definition shared with the write path (no
 * risk of a read-model figure drifting from the money authority).
 *
 * Every method is a plain read (no writes, no locks); each guards N+1 — the history
 * feed eager-loads its staff in a fixed number of queries, and active-lots is a
 * single query with no per-row hydration.
 */
final class MemberWalletQuery
{
    public function __construct(
        private readonly WalletService $wallet,
    ) {
    }

    /**
     * The member's single spendable-balance figure, as a decimal-2 STRING (e.g.
     * "1290.00"). Delegates to the money authority so the dashboard headline and the
     * debit sufficiency gate can never disagree.
     */
    public function balance(Member $member): string
    {
        return $this->wallet->balance($member);
    }

    /**
     * Recent wallet movements for the statement / activity feed (idx_credit_ledger_
     * member_created), newest first, capped at `$limit` (~50). Unlike the old
     * entitlement feed this shows ALL reasons (topup, bonus, debit, refund, expire,
     * adjust) — a money statement lists every movement.
     *
     * `$includeStaff` gates the staff column exactly as before: the admin detail
     * page passes `true` (eager-loads `staff:id,name`, emits `staff_name`); the
     * member dashboard passes `false` so the customer feed can't leak who performed
     * a movement. Money fields (`delta`, `balance_after`) are STRINGS.
     *
     * @return ($includeStaff is true
     *     ? list<array{
     *         id: int,
     *         created_at: string|null,
     *         reason: string,
     *         delta: string,
     *         balance_after: string,
     *         note: string|null,
     *         credit_lot_id: int|null,
     *         booking_id: int|null,
     *         staff_name: string|null
     *     }>
     *     : list<array{
     *         id: int,
     *         created_at: string|null,
     *         reason: string,
     *         delta: string,
     *         balance_after: string,
     *         note: string|null,
     *         credit_lot_id: int|null,
     *         booking_id: int|null
     *     }>
     * )
     */
    public function recentHistory(Member $member, bool $includeStaff = false, int $limit = 50): array
    {
        $with = [];
        if ($includeStaff) {
            $with[] = 'staff:id,name';
        }

        return CreditLedger::query()
            ->where('member_id', $member->id)
            ->with($with)
            ->orderByDesc('id')
            ->limit($limit)
            ->get(['id', 'member_id', 'credit_lot_id', 'delta', 'reason', 'balance_after', 'booking_id', 'staff_id', 'note', 'created_at'])
            ->map(function (CreditLedger $row) use ($includeStaff): array {
                $line = [
                    'id' => $row->id,
                    'created_at' => $row->created_at?->toIso8601String(),
                    'reason' => $row->reason->value,
                    // Money stays a STRING (decimal:2 cast) — no int/float coercion.
                    'delta' => (string) $row->delta,
                    'balance_after' => (string) $row->balance_after,
                    'note' => $row->note,
                    'credit_lot_id' => $row->credit_lot_id,
                    'booking_id' => $row->booking_id,
                ];

                if ($includeStaff) {
                    $line['staff_name'] = $row->staff?->name;
                }

                return $line;
            })
            ->all();
    }

    /**
     * The member's ACTIVE credit lots for the dashboard "เครดิตของคุณ" section: each
     * `credit_lots` row with status Active, its paid/bonus remaining, total
     * remaining, and a near-expiry flag. Ordered near-expiry-first (dated lots
     * closest to expiry lead, never-expiring last), then newest.
     *
     * Money fields are decimal-2 STRINGS. `is_near_expiry` is always false while the
     * expiry capability stays off (every lot ships `expires_at = null`).
     *
     * N+1 guard: a single query, no per-row hydration.
     *
     * @param  int  $nearExpiryDays  a dated lot expiring within this many days (and
     *                               still in the future) is flagged near-expiry.
     * @return list<array{
     *     id: int,
     *     source: string,
     *     amount_paid: string,
     *     bonus_amount: string,
     *     paid_remaining: string,
     *     bonus_remaining: string,
     *     total_remaining: string,
     *     purchased_at: string|null,
     *     expires_at: string|null,
     *     is_near_expiry: bool
     * }>
     */
    public function activeLots(Member $member, int $nearExpiryDays = 30): array
    {
        return CreditLot::query()
            ->where('member_id', $member->id)
            ->where('status', CreditLotStatus::Active)
            // Near-expiry first: never-expiring (null) sort LAST, dated ascend by
            // expiry; newest lot id breaks ties.
            ->orderByRaw('expires_at IS NULL asc')
            ->orderBy('expires_at')
            ->orderByDesc('id')
            ->get(['id', 'source', 'amount_paid', 'bonus_amount', 'paid_remaining', 'bonus_remaining', 'expires_at', 'purchased_at'])
            ->map(fn (CreditLot $lot): array => [
                'id' => $lot->id,
                'source' => $lot->source->value,
                'amount_paid' => (string) $lot->amount_paid,
                'bonus_amount' => (string) $lot->bonus_amount,
                'paid_remaining' => (string) $lot->paid_remaining,
                'bonus_remaining' => (string) $lot->bonus_remaining,
                'total_remaining' => bcadd((string) $lot->paid_remaining, (string) $lot->bonus_remaining, 2),
                'purchased_at' => $lot->purchased_at?->toIso8601String(),
                'expires_at' => $lot->expires_at?->toIso8601String(),
                'is_near_expiry' => $this->isNearExpiry($lot->expires_at, $nearExpiryDays),
            ])
            ->all();
    }

    /**
     * Whether a lot's expiry falls inside the near-expiry window: it has a dated
     * expiry (never-expiring lots are never "near"), that date is still in the
     * future, and it lands within `$nearExpiryDays` from now.
     *
     * Typed `?CarbonInterface` — the app aliases Date to CarbonImmutable, which is
     * NOT an instance of Illuminate\Support\Carbon, so comparisons stay immutable-safe.
     */
    private function isNearExpiry(?CarbonInterface $expiresAt, int $nearExpiryDays): bool
    {
        if ($expiresAt === null) {
            return false;
        }

        return $expiresAt->greaterThan(now())
            && $expiresAt->lessThanOrEqualTo(now()->addDays($nearExpiryDays));
    }
}
