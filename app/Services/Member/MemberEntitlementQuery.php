<?php

declare(strict_types=1);

namespace App\Services\Member;

use App\Enums\EntitlementStatus;
use App\Enums\LedgerReason;
use App\Models\Entitlement;
use App\Models\EntitlementLedger;
use App\Models\MemberPackage;
use Carbon\CarbonInterface;

/**
 * MemberEntitlementQuery — the SINGLE source of truth for read-only balance /
 * history / lot projections of a member's entitlements (architecture.md §6.4).
 *
 * Extracted from the private helpers that used to live in
 * App\Http\Controllers\Admin\MemberController (`remainingByType`,
 * `redemptionHistory`) so BOTH the admin detail page (Phase 4) and the
 * member-facing dashboard (Phase 6) render the exact same numbers. The admin
 * caller keeps its `staff_name` column; the member caller omits it entirely so
 * the customer-facing feed never leaks who performed a movement.
 *
 * Every method is a plain read (no writes); each guards N+1 per §6.4 — grouped
 * aggregates run as a SINGLE query, feeds eager-load their labels/staff in a
 * fixed number of extra queries (not one per row).
 */
final class MemberEntitlementQuery
{
    /**
     * Aggregate live balance grouped by item (architecture.md §6.4 aggregate): a
     * single grouped SUM over ACTIVE, non-expired entitlements. `expires_at IS
     * NULL` (never-expires) counts; dated-but-not-yet-expired counts. Returned as
     * one row per distinct `item_code`/`item_name` so callers can render a compact
     * "remaining by type" summary.
     *
     * @return array<int, array{item_code: string, item_name: string, remaining: int}>
     */
    public function remainingByType(int $memberId): array
    {
        return Entitlement::query()
            ->where('member_id', $memberId)
            ->where('status', EntitlementStatus::Active)
            // Never-expiring (null) OR still in the future — leans on index I2.
            ->where(function ($w): void {
                $w->whereNull('expires_at')
                    ->orWhere('expires_at', '>', now());
            })
            ->groupBy('item_code', 'item_name')
            ->orderBy('item_name')
            ->selectRaw('item_code, item_name, SUM(qty_remaining) AS remaining')
            ->get()
            ->map(fn ($row): array => [
                'item_code' => (string) $row->item_code,
                'item_name' => (string) $row->item_name,
                'remaining' => (int) $row->remaining,
            ])
            ->all();
    }

    /**
     * Recent entitlement movements for the member's activity feed (§6.4, I6
     * `(member_id, created_at)`): consumption-relevant reasons (redeem, expire,
     * refund), newest first, capped at 50. Eager-loads `entitlement:id,item_name`
     * (the label) so the list renders with NO query per row.
     *
     * `$includeStaff` gates the staff column: the admin detail page needs to see
     * WHO performed a movement, so it passes `true` (eager-loads `staff:id,name`
     * and emits `staff_name`). The member dashboard passes `false` — the
     * `staff_name` key is OMITTED entirely so the customer feed can't leak it.
     *
     * @return ($includeStaff is true
     *     ? list<array{
     *         id: int,
     *         created_at: string|null,
     *         item_name: string|null,
     *         reason: string,
     *         delta: int,
     *         balance_after: int,
     *         staff_name: string|null
     *     }>
     *     : list<array{
     *         id: int,
     *         created_at: string|null,
     *         item_name: string|null,
     *         reason: string,
     *         delta: int,
     *         balance_after: int
     *     }>
     * )
     */
    public function recentHistory(int $memberId, bool $includeStaff): array
    {
        // Only load the staff relation when the caller will render it — the member
        // feed must not query (or expose) who performed the movement.
        $with = ['entitlement:id,item_name'];
        if ($includeStaff) {
            $with[] = 'staff:id,name';
        }

        return EntitlementLedger::query()
            ->where('member_id', $memberId)
            ->whereIn('reason', [LedgerReason::Redeem, LedgerReason::Expire, LedgerReason::Refund])
            // N+1 guard: load the entitlement label (+ optionally the staff name)
            // in a fixed number of extra queries, not one per row.
            ->with($with)
            ->orderByDesc('id')
            ->limit(50)
            ->get(['id', 'entitlement_id', 'member_id', 'delta', 'reason', 'balance_after', 'staff_id', 'created_at'])
            ->map(function (EntitlementLedger $row) use ($includeStaff): array {
                $line = [
                    'id' => $row->id,
                    'created_at' => $row->created_at?->toIso8601String(),
                    'item_name' => $row->entitlement?->item_name,
                    'reason' => $row->reason->value,
                    'delta' => (int) $row->delta,
                    'balance_after' => (int) $row->balance_after,
                ];

                // Admin only — the member view never receives this key.
                if ($includeStaff) {
                    $line['staff_name'] = $row->staff?->name;
                }

                return $line;
            })
            ->all();
    }

    /**
     * The member's ACTIVE lots for the dashboard "แพ็กเกจของคุณ" section — each
     * owned `member_packages` row whose status is Active, with ALL its items
     * (including qty_remaining 0 — the member sees the full package). Ordered
     * near-expiry-first (dated lots closest to expiry lead), then newest.
     *
     * N+1 guard: eager-load `entitlements` in ONE pass so every item renders
     * without a query per lot (§6.4). `package_id` is SET NULL after catalog
     * cleanup (§5.1), so `package_name` falls back to null via `optional()`.
     *
     * @param  int  $nearExpiryDays  a dated lot expiring within this many days
     *                               (and still in the future) is flagged near-expiry.
     * @return array<int, array{
     *     id: int,
     *     package_name: string|null,
     *     purchased_at: string|null,
     *     expires_at: string|null,
     *     is_near_expiry: bool,
     *     items: array<int, array{
     *         item_name: string,
     *         item_type: string,
     *         qty_remaining: int,
     *         qty_total: int
     *     }>
     * }>
     */
    public function activeLots(int $memberId, int $nearExpiryDays = 30): array
    {
        return MemberPackage::query()
            ->where('member_id', $memberId)
            ->where('status', EntitlementStatus::Active)
            // Provenance package name (nullable after SET NULL) in one join;
            // items eager-loaded in a single extra query (not per lot).
            ->with(['package:id,name', 'entitlements'])
            // Near-expiry first: never-expiring lots (null) sort LAST, dated lots
            // ascend by expiry; then newest lot id breaks ties.
            ->orderByRaw('expires_at IS NULL asc, expires_at asc')
            ->orderByDesc('id')
            ->get()
            ->map(fn (MemberPackage $lot): array => [
                'id' => $lot->id,
                // package_id is SET NULL after catalog delete (§5.1) — null then.
                'package_name' => optional($lot->package)->name,
                'purchased_at' => $lot->purchased_at?->toIso8601String(),
                'expires_at' => $lot->expires_at?->toIso8601String(),
                'is_near_expiry' => $this->isNearExpiry($lot->expires_at, $nearExpiryDays),
                'items' => $lot->entitlements
                    ->map(fn (Entitlement $ent): array => [
                        'item_name' => $ent->item_name,
                        'item_type' => $ent->item_type->value,
                        'qty_remaining' => (int) $ent->qty_remaining,
                        'qty_total' => (int) $ent->qty_total,
                    ])
                    ->all(),
            ])
            ->all();
    }

    /**
     * Whether a lot's expiry falls inside the near-expiry window: it has a dated
     * expiry (never-expiring lots are never "near"), that date is still in the
     * future, and it lands within `$nearExpiryDays` from now.
     *
     * Typed as `?CarbonInterface` — the app aliases Date to CarbonImmutable
     * (AppServiceProvider::configureDefaults), which is NOT an instance of
     * Illuminate\Support\Carbon, so comparisons stay immutable-safe.
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
