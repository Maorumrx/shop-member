<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * credit_lots — one row per top-up batch (the money-wallet reframe of the dropped
 * `member_packages` lot). A FINANCIAL record: the unit of per-lot (optional) expiry
 * and — critically — the unit that keeps PAID money and BONUS money separate so a
 * refund can return only the paid portion.
 *
 * PAID vs BONUS (client rule): a top-up records `amount_paid` (cash in) and
 * `bonus_amount` (promotional grant), then tracks each independently as it is spent
 * via `paid_remaining` and `bonus_remaining`. The lot's spendable value at any time
 * is `paid_remaining + bonus_remaining`. Debits spend BONUS FIRST within a lot
 * (bonus_remaining down to 0, then paid_remaining), so promotional value is used up
 * before the refundable cash. A refund reverses `paid_remaining` only — bonus is
 * never returned.
 *
 * EXPIRY (client: TBD): `expires_at` is NULLABLE and every lot ships with it NULL =
 * never expires. The capability is built now (index + status + expiry job hook) but
 * stays OFF until the client sets a policy; do not block on it.
 *
 * FK on-delete (mirrors the dropped member_packages, §5.4):
 *   - member_id           → members  RESTRICT  (protect the financial record; members
 *                                               are soft-deleted, never hard-deleted)
 *   - branch_id           → branches SET NULL  (snapshot of WHERE the top-up happened;
 *                                               lot becomes any-branch if branch deleted)
 *   - created_by_user_id  → users    SET NULL  (keep the lot if a staff account is removed)
 *
 * INVARIANT (per lot): paid_remaining + bonus_remaining == (amount_paid + bonus_amount)
 * + Σ(credit_ledger.delta for this lot). The lot's remainings are a reconcilable cache
 * of the append-only credit_ledger, exactly as entitlements.qty_remaining was of
 * entitlement_ledger.
 *
 * All money is decimal(10,2), NEVER float (§5.6).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('credit_lots', function (Blueprint $table) {
            $table->id();

            // Owner. RESTRICT: protect the financial record (§5.4).
            $table->foreignId('member_id')
                ->constrained('members')
                ->restrictOnDelete();

            // How the lot was created (paying top-up vs manual owner grant).
            $table->enum('source', ['topup', 'adjustment'])->default('topup');

            // ---- Original amounts at sale (frozen) ----
            $table->decimal('amount_paid', 10, 2);   // cash in
            $table->decimal('bonus_amount', 10, 2);  // promotional grant

            // ---- Remaining, tracked SEPARATELY (bonus spent first; refund = paid only) ----
            $table->decimal('paid_remaining', 10, 2);
            $table->decimal('bonus_remaining', 10, 2);

            // Per-lot expiry. NULL = never (default). Capability built, kept OFF.
            $table->dateTime('expires_at')->nullable();
            // Dedup marker for the (currently-off) near-expiry reminder — stamped once
            // when a push is queued so the daily command stays idempotent. Nullable;
            // unused until expiry is enabled (parallels the dropped
            // member_packages.expiry_reminded_at).
            $table->dateTime('expiry_reminded_at')->nullable();

            $table->enum('status', ['active', 'used_up', 'expired'])->default('active');

            $table->dateTime('purchased_at');

            // Snapshot of WHERE the top-up happened. SET NULL on branch delete.
            $table->foreignId('branch_id')
                ->nullable()
                ->constrained('branches')
                ->nullOnDelete();

            // Who performed the top-up/adjustment. SET NULL keeps the lot if the
            // staff account is later removed.
            $table->foreignId('created_by_user_id')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            $table->timestamps();

            // FIFO debit set: a member's active lots ordered by expiry. Mirrors the
            // dropped entitlements I1 shape (member_id, status, expires_at). Also
            // serves "member's active lots" (member_id, status prefix).
            $table->index(['member_id', 'status', 'expires_at'], 'idx_credit_lots_fifo');
            // Expiry job daily scan (dated, still-active lots).
            $table->index(['status', 'expires_at'], 'idx_credit_lots_status_expires');
            // (branch_id / created_by_user_id indexes auto-created by constrained().)
        });

        // CHECK constraints (§5.6 exactness backed by DB guards). MariaDB enforces;
        // guarded so the transient sqlite test DB doesn't choke (Phase-1 pattern).
        // - all money non-negative;
        // - a remaining can never exceed its original (a debit/refund/expire can only
        //   reduce it; there is no "spend more paid than was paid" state).
        if (DB::getDriverName() !== 'sqlite') {
            DB::statement(
                'ALTER TABLE credit_lots ADD CONSTRAINT chk_credit_lots_amounts CHECK ('
                . 'amount_paid >= 0 AND bonus_amount >= 0 '
                . 'AND paid_remaining >= 0 AND bonus_remaining >= 0 '
                . 'AND paid_remaining <= amount_paid '
                . 'AND bonus_remaining <= bonus_amount'
                . ')'
            );
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'sqlite') {
            DB::statement('ALTER TABLE credit_lots DROP CONSTRAINT chk_credit_lots_amounts');
        }

        Schema::dropIfExists('credit_lots');
    }
};
