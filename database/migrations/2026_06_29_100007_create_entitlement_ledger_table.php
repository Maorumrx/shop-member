<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

/**
 * Append-only ledger — source of truth (§3.8, §5.2). Every entitlement movement
 * is one immutable row. NO `updated_at` (rows never change — immutability is
 * structural): only created_at useCurrent. member_id is denormalized for
 * member-level statements without a join.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('entitlement_ledger', function (Blueprint $table) {
            $table->id();

            // Which entitlement moved. CASCADE — ledger lives and dies with its
            // entitlement (in practice neither is deleted) (§3.8).
            $table->foreignId('entitlement_id')
                ->constrained('entitlements')
                ->cascadeOnDelete();

            // Denormalized owner. RESTRICT — the ledger is the audit truth; never
            // cascade-deleted via a member (§3.8, §5.4).
            $table->foreignId('member_id')
                ->constrained('members')
                ->restrictOnDelete();

            // SIGNED int: +qty on purchase/refund, -qty on redeem/expire (§3.8).
            $table->integer('delta');

            $table->enum('reason', ['purchase', 'redeem', 'expire', 'refund', 'adjust']);

            // qty_remaining AFTER this row applied (reconcile chain check, §3.8).
            $table->unsignedInteger('balance_after');

            // Forward-ref Phase 5 (§3.8): nullable column, NO foreign key yet.
            // The FK booking_id → bookings.id is added in the Phase-5 migration
            // that creates `bookings`. Documented so the column isn't repurposed.
            $table->unsignedBigInteger('booking_id')->nullable();

            // users.id who performed it; null for system jobs (expiry).
            // SET NULL — keep ledger even if a staff account is removed (§3.8).
            $table->foreignId('staff_id')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            $table->string('note', 255)->nullable();

            // No timestamps()/updated_at — append-only (§3.8). created_at useCurrent.
            $table->timestamp('created_at')->useCurrent();

            // I5: reconcile + single-entitlement history replay in insertion order
            // (id tiebreaker since created_at can collide).
            $table->index(['entitlement_id', 'id'], 'idx_ledger_entitlement_id');
            // I6: member transaction statement / activity feed.
            $table->index(['member_id', 'created_at'], 'idx_ledger_member_created');
            // I7: audit reports by movement type.
            $table->index(['reason', 'created_at'], 'idx_ledger_reason_created');
            // (staff_id index auto-created by foreignId()->constrained().)
        });

        // CHECK constraint (§3.8). Guarded so sqlite doesn't choke.
        if (DB::getDriverName() !== 'sqlite') {
            DB::statement(
                'ALTER TABLE entitlement_ledger ADD CONSTRAINT chk_ledger_balance '
                . 'CHECK (balance_after >= 0)'
            );
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'sqlite') {
            DB::statement('ALTER TABLE entitlement_ledger DROP CONSTRAINT chk_ledger_balance');
        }

        Schema::dropIfExists('entitlement_ledger');
    }
};
