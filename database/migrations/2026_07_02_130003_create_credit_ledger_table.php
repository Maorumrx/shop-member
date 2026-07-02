<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * credit_ledger — the APPEND-ONLY money ledger and single source of truth (the
 * money-wallet reframe of the dropped `entitlement_ledger`). Every wallet movement
 * is one immutable row: `delta` (signed baht), `reason`, and `balance_after` (the
 * member's TOTAL spendable wallet balance AFTER this row applied). NO `updated_at` —
 * immutability is structural; the model also forbids UPDATE/DELETE at runtime.
 *
 * THE INVARIANT (reconcilable at any time):
 *   member spendable balance
 *     == SUM(credit_lots.paid_remaining + bonus_remaining WHERE status = active)
 *     == the member's latest credit_ledger.balance_after
 * and per lot: (paid_remaining + bonus_remaining)
 *     == (amount_paid + bonus_amount) + Σ(delta for that credit_lot_id).
 *
 * ROWS PER OPERATION (see the credit_lots writeup + the service phase):
 *   - TOP-UP:  1 row reason=topup (+amount_paid) and, if bonus>0, 1 row reason=bonus
 *              (+bonus_amount) — paid vs bonus stay separated end-to-end.
 *   - DEBIT:   1 row reason=debit (-taken) PER credit_lot the visit consumes (FIFO),
 *              booking_id set when it is a booking check-in.
 *   - REFUND:  1 row reason=refund (-amount) — reverses PAID value only.
 *   - EXPIRE:  1 row reason=expire (-remaining) per lot the (off-by-default) job zeroes.
 *   - ADJUST:  1 row reason=adjust (±) — manual owner correction (note carries why).
 *
 * FK on-delete (mirrors the dropped entitlement_ledger, §3.8/§5.4):
 *   - member_id      → members   RESTRICT  (the ledger is the audit truth; never cascade)
 *   - credit_lot_id  → credit_lots SET NULL (nullable: a system/adjust row may not target
 *                                            a single lot; keep the row if a lot is ever
 *                                            removed — lots aren't normally deleted)
 *   - booking_id     → bookings  SET NULL  (financial row outlives the scheduling row)
 *   - staff_id       → users     SET NULL  (null for system jobs e.g. expiry)
 *
 * Money is decimal(10,2), NEVER float (§5.6). `delta` is SIGNED (negative on
 * debit/refund/expire); `balance_after` carries a CHECK >= 0.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('credit_ledger', function (Blueprint $table) {
            $table->id();

            // Denormalized owner. RESTRICT — audit truth, never cascade-deleted.
            $table->foreignId('member_id')
                ->constrained('members')
                ->restrictOnDelete();

            // Which lot moved. Nullable + SET NULL: most rows target one lot, but a
            // system/adjust row may not, and the ledger outlives a removed lot.
            $table->foreignId('credit_lot_id')
                ->nullable()
                ->constrained('credit_lots')
                ->nullOnDelete();

            // SIGNED baht: +topup/+bonus/+adjust, -debit/-refund/-expire (§5.6).
            $table->decimal('delta', 10, 2);

            $table->enum('reason', ['topup', 'bonus', 'debit', 'refund', 'expire', 'adjust']);

            // Member's TOTAL wallet balance AFTER this row (reconcile-chain check).
            $table->decimal('balance_after', 10, 2);

            // Set when the movement is a booking check-in debit — links a completed
            // booking to exactly what it spent. SET NULL keeps the ledger truth if a
            // booking is ever hard-removed (bookings is soft-deleted, so rare).
            $table->foreignId('booking_id')
                ->nullable()
                ->constrained('bookings')
                ->nullOnDelete();

            // Acting operator; null for system jobs (expiry). SET NULL on user delete.
            $table->foreignId('staff_id')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            $table->string('note', 255)->nullable();

            // No timestamps()/updated_at — append-only. created_at useCurrent.
            $table->timestamp('created_at')->useCurrent();

            // Per-lot history replay + reconcile in insertion order (id tiebreak).
            $table->index(['credit_lot_id', 'id'], 'idx_credit_ledger_lot_id');
            // Member transaction statement / activity feed.
            $table->index(['member_id', 'created_at'], 'idx_credit_ledger_member_created');
            // Audit reports by movement type.
            $table->index(['reason', 'created_at'], 'idx_credit_ledger_reason_created');
            // "What did this booking spend" (WHERE booking_id = ?).
            $table->index('booking_id', 'idx_credit_ledger_booking_id');
            // (staff_id / credit_lot_id / booking_id FK auto-indexes: credit_lot_id and
            //  booking_id are led by the composites above; staff_id auto-created.)
        });

        // CHECK: wallet balance can never go negative. Guarded for sqlite.
        if (DB::getDriverName() !== 'sqlite') {
            DB::statement(
                'ALTER TABLE credit_ledger ADD CONSTRAINT chk_credit_ledger_balance '
                . 'CHECK (balance_after >= 0)'
            );
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'sqlite') {
            DB::statement('ALTER TABLE credit_ledger DROP CONSTRAINT chk_credit_ledger_balance');
        }

        Schema::dropIfExists('credit_ledger');
    }
};
