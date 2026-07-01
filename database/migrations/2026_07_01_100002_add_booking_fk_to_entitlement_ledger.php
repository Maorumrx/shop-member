<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Fulfil the Phase-1 DEFERRED FK (docs/phase7-booking-design.md §7, P7-3;
 * architecture.md §3.8). The `entitlement_ledger.booking_id` COLUMN already
 * exists (nullable, no constraint) — this migration only adds the foreign key
 * and its index now that `bookings` exists.
 *
 *   - Index I19 `(booking_id)` serves "what did this booking consume"
 *     (`WHERE booking_id = ?`). Created on every driver (indexes are portable).
 *   - FK `entitlement_ledger.booking_id → bookings.id` ON DELETE SET NULL: a
 *     ledger row is the append-only financial truth and must survive even if a
 *     booking is force-removed; the financial record outlives the scheduling row
 *     (§7). Because `bookings` uses SoftDeletes, this FK is rarely exercised —
 *     SET NULL is the belt-and-suspenders choice.
 *
 * The FK is added ONLY on MariaDB/MySQL — guarded against sqlite like the Phase-1
 * CHECK constraints. sqlite can't add a FK to an already-existing column without a
 * full table rebuild (and its RESTRICT/SET NULL semantics differ), so on the
 * transient sqlite test DB we keep just the index; the real MariaDB deploy gets
 * the constraint.
 */
return new class extends Migration
{
    public function up(): void
    {
        // I19: portable — added on every driver.
        Schema::table('entitlement_ledger', function (Blueprint $table) {
            $table->index('booking_id', 'idx_ledger_booking_id');
        });

        // Deferred FK from Phase 1 — MariaDB/MySQL only (SET NULL keeps the ledger
        // truth if a booking is ever hard-removed, §7). Column already exists;
        // constrain it in place.
        if (DB::getDriverName() !== 'sqlite') {
            Schema::table('entitlement_ledger', function (Blueprint $table) {
                $table->foreign('booking_id', 'fk_ledger_booking_id')
                    ->references('id')
                    ->on('bookings')
                    ->nullOnDelete();
            });
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'sqlite') {
            Schema::table('entitlement_ledger', function (Blueprint $table) {
                $table->dropForeign('fk_ledger_booking_id');
            });
        }

        Schema::table('entitlement_ledger', function (Blueprint $table) {
            $table->dropIndex('idx_ledger_booking_id');
        });
    }
};
