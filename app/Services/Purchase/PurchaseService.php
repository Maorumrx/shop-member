<?php

declare(strict_types=1);

namespace App\Services\Purchase;

use App\Enums\EntitlementStatus;
use App\Enums\LedgerReason;
use App\Exceptions\PurchaseException;
use App\Models\Entitlement;
use App\Models\Member;
use App\Models\MemberPackage;
use App\Models\Package;
use App\Models\PackageLine;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * PurchaseService — the HEART of the system (architecture.md §3.6–§3.8, §5.1–§5.2).
 *
 * Selling a package MINTS the append-only ledger that Phase-5 redemption trusts.
 * A single sale produces, atomically:
 *   1. one `member_packages` "lot" (the unit of per-lot expiry + redemption scope),
 *   2. one `entitlements` row PER `package_line` — every descriptor SNAPSHOTTED from
 *      the catalog at purchase so a later catalog edit can never rewrite history (§5.1),
 *   3. one `entitlement_ledger` row PER entitlement (reason=purchase, +qty_total) — the
 *      source-of-truth movement. A flipped qty with NO matching ledger row would break
 *      the reconcile invariant (§6.1), so the ledger write is non-negotiable.
 *
 * INVARIANT guaranteed immediately after purchase, for every entitlement:
 *     qty_remaining == qty_total == ledger.balance_after   (and == qty_total + Σ delta)
 *
 * Everything runs inside ONE DB::transaction — a partial sale (a lot with missing
 * entitlements, or an entitlement with no ledger row) must never persist.
 *
 * Money: `price_paid` is decimal(10,2). It is accepted as a validated string/numeric
 * and passed through to the column AS-IS — NEVER cast to float (§5.6).
 */
final class PurchaseService
{
    /**
     * Sell one $package to $member, performed by $staff, for $pricePaid.
     *
     * Atomic: mints the lot, its per-line entitlements (snapshots), and one
     * purchase ledger row per entitlement, all in a single transaction.
     *
     * @param  Member   $member     The buyer. Caller MUST have rejected inactive /
     *                              soft-deleted members at validation (the service
     *                              does not re-check member status — that is a
     *                              request-layer concern, see StorePurchaseRequest).
     * @param  Package  $package    The catalog package being sold.
     * @param  string   $pricePaid  THB actually charged, as a decimal(10,2) string
     *                              (e.g. "1290.00"). Pass the package list price when
     *                              the operator did not override it. NEVER a float.
     * @param  User     $staff      The owner/staff who performed the sale; recorded
     *                              as `entitlement_ledger.staff_id` for audit.
     *
     * @return MemberPackage The created lot with its `entitlements` relation loaded.
     *
     * @throws PurchaseException When the package is inactive or has no lines (§3.4).
     */
    public function purchase(Member $member, Package $package, string $pricePaid, User $staff): MemberPackage
    {
        return DB::transaction(function () use ($member, $package, $pricePaid, $staff): MemberPackage {
            // (1) Snapshot source: eager-load the catalog lines once (no N+1 — we read
            // them all up front and value-copy each into an entitlement below, §5.1, §6.4).
            $package->loadMissing('lines');
            $lines = $package->lines;

            // Domain guards — abort BEFORE writing any row. A sale must yield a
            // redeemable lot with at least one entitlement (§3.4, §3.5, §3.7).
            if (! $package->is_active) {
                throw PurchaseException::inactivePackage($package);
            }

            if ($lines->isEmpty()) {
                throw PurchaseException::noLines($package);
            }

            // (2) Timing. `now()` is CarbonImmutable (AppServiceProvider::configureDefaults),
            // so addDays() returns a fresh instance and never mutates $purchasedAt.
            // valid_days null = the lot (and every entitlement) never expires (§3.4, §3.6).
            $purchasedAt = now();
            $expiresAt = $package->valid_days !== null
                ? $purchasedAt->addDays($package->valid_days)
                : null;

            // (3) The lot. branch_id is the SNAPSHOT of the package's redemption scope
            // (§5.5) — NOT the staff's home branch. Null = redeemable at any branch.
            // price_paid is written from the validated string, never a float (§5.6).
            $memberPackage = MemberPackage::create([
                'member_id' => $member->id,
                'package_id' => $package->id,
                'branch_id' => $package->branch_id,
                'purchased_at' => $purchasedAt,
                'expires_at' => $expiresAt,
                'price_paid' => $pricePaid,
                'status' => EntitlementStatus::Active,
            ]);

            // (4) + (5) One entitlement + one purchase ledger row PER catalog line.
            foreach ($lines as $line) {
                /** @var PackageLine $line */
                $qty = (int) $line->qty;

                // (4) Snapshot the item descriptors from the catalog line. member_id and
                // expires_at are denormalized so the hot redemption query needs no lot
                // join (§3.7). qty_remaining starts == qty_total (nothing consumed yet).
                $entitlement = Entitlement::create([
                    'member_package_id' => $memberPackage->id,
                    'member_id' => $member->id,
                    'item_code' => $line->item_code,
                    'item_name' => $line->item_name,
                    'item_type' => $line->item_type,
                    'qty_total' => $qty,
                    'qty_remaining' => $qty,
                    'redeem_group' => $line->redeem_group,
                    'expires_at' => $expiresAt,
                    'status' => EntitlementStatus::Active,
                ]);

                // (5) The MANDATORY purchase ledger row. delta = +qty_total and
                // balance_after = qty_total, so the §6.1 invariant
                // (qty_remaining == qty_total == balance_after) holds the instant the
                // sale commits. booking_id is null (a Phase-5 redemption concern).
                // staff_id records who sold it. This INSERT is the only ledger
                // operation allowed — the model forbids update/delete (§3.8, §5.2).
                $entitlement->ledgerEntries()->create([
                    'member_id' => $member->id,
                    'delta' => $qty,
                    'reason' => LedgerReason::Purchase,
                    'balance_after' => $qty,
                    'booking_id' => null,
                    'staff_id' => $staff->id,
                    'note' => null,
                ]);
            }

            // (6) Return the lot with its entitlements loaded so the caller (and the
            // redirected Show page) renders the fresh balance without an extra query.
            return $memberPackage->load('entitlements');
        });
    }
}
