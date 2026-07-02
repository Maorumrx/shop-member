<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * CREDIT-WALLET CUTOVER (Phase 1) — drop the entire package / session-entitlement
 * subsystem. The app is moving from "buy N sessions of item X" to a money-based
 * stored-value wallet (see the credit_lots / credit_ledger / services /
 * topup_offers migrations that follow). This is a CLEAN cutover: production holds
 * ~no real package data, so there is NO data migration — the old tables are simply
 * removed and the new ones created empty.
 *
 * Dropped, in FK-safe (reverse-dependency) order:
 *   entitlement_ledger → entitlements → member_packages → package_lines → packages
 * (entitlement_ledger.booking_id → bookings is a SET NULL FK; it disappears with
 * the table. `bookings` itself is KEPT — it is reworked to debit credit at
 * check-in in a later phase.)
 *
 * KEPT untouched: members, branches, bookings, branch_booking_settings, users,
 * settings, cache/jobs, passkeys.
 *
 * DEPLOY ORDER WARNING (flag for the reviewer): after this migration the legacy
 * Eloquent models (MemberPackage/Entitlement/EntitlementLedger/Package/PackageLine)
 * and the code that uses them still reference these now-gone tables and WILL error
 * if exercised — PurchaseService, RedemptionService, MemberEntitlementQuery,
 * RemindExpiring, BookingService::checkIn, the Admin Package/Purchase/Redemption
 * controllers + requests, DemoSeeder, and the member DashboardController. Those are
 * reworked in the backend/frontend phases; do NOT deploy this migration to prod
 * until the dependent phases land. (Phase 1 = schema only, per the task.)
 *
 * REVERSIBLE: down() faithfully recreates all five tables in dependency order,
 * folding in the two later alterations (member_packages.expiry_reminded_at and the
 * entitlement_ledger.booking_id column + FK/index) so a rollback restores the exact
 * pre-cutover STRUCTURE. It does NOT restore ROWS — a clean cutover discards data by
 * design; restore rows from backup if ever required. CHECK/late-FK statements are
 * guarded against sqlite exactly as the original Phase-1 migrations were.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Drop late-added constraints/indexes first where a bare dropIfExists would
        // otherwise trip a dependency, then the tables in reverse-dependency order.
        // (dropIfExists on the child tables removes their own FKs/CHECKs with them.)
        Schema::dropIfExists('entitlement_ledger');
        Schema::dropIfExists('entitlements');
        Schema::dropIfExists('member_packages');
        Schema::dropIfExists('package_lines');
        Schema::dropIfExists('packages');
    }

    public function down(): void
    {
        // Recreate in dependency order (parents first). Mirrors the original
        // create migrations + the two later ALTERs, so the restored schema is
        // byte-for-byte the pre-cutover final state.

        // --- packages (orig 100003) ---
        Schema::create('packages', function (Blueprint $table) {
            $table->id();
            $table->string('name', 150);
            $table->decimal('price', 10, 2);
            $table->unsignedInteger('valid_days')->nullable();
            $table->foreignId('branch_id')
                ->nullable()
                ->constrained('branches')
                ->restrictOnDelete();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->index('is_active', 'idx_packages_is_active');
        });

        // --- package_lines (orig 100004) ---
        Schema::create('package_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('package_id')
                ->constrained('packages')
                ->cascadeOnDelete();
            $table->string('item_code', 40);
            $table->string('item_name', 150);
            $table->enum('item_type', ['service', 'addon'])->default('service');
            $table->unsignedInteger('qty');
            $table->string('redeem_group', 40)->nullable();
            $table->timestamps();
            $table->unique(['package_id', 'item_code'], 'uq_package_lines_pkg_item');
            $table->index(['package_id', 'redeem_group'], 'idx_package_lines_pkg_group');
        });

        // --- member_packages (orig 100005 + expiry_reminded_at from 110001) ---
        Schema::create('member_packages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('member_id')
                ->constrained('members')
                ->restrictOnDelete();
            $table->foreignId('package_id')
                ->nullable()
                ->constrained('packages')
                ->nullOnDelete();
            $table->foreignId('branch_id')
                ->nullable()
                ->constrained('branches')
                ->nullOnDelete();
            $table->dateTime('purchased_at');
            $table->dateTime('expires_at')->nullable();
            $table->dateTime('expiry_reminded_at')->nullable(); // folded from 110001
            $table->decimal('price_paid', 10, 2);
            $table->enum('status', ['active', 'expired', 'used_up'])->default('active');
            $table->timestamps();
            $table->index(['member_id', 'status'], 'idx_member_packages_member_status');
            $table->index(['status', 'expires_at'], 'idx_member_packages_status_expires');
        });

        // --- entitlements (orig 100006 + chk_ent_qty) ---
        Schema::create('entitlements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('member_package_id')
                ->constrained('member_packages')
                ->cascadeOnDelete();
            $table->foreignId('member_id')
                ->constrained('members')
                ->restrictOnDelete();
            $table->string('item_code', 40);
            $table->string('item_name', 150);
            $table->enum('item_type', ['service', 'addon']);
            $table->unsignedInteger('qty_total');
            $table->unsignedInteger('qty_remaining');
            $table->string('redeem_group', 40)->nullable();
            $table->dateTime('expires_at')->nullable();
            $table->enum('status', ['active', 'expired', 'used_up'])->default('active');
            $table->timestamps();
            $table->index(
                ['member_id', 'item_code', 'status', 'expires_at', 'qty_remaining'],
                'idx_entitlements_fifo'
            );
            $table->index(
                ['member_id', 'item_type', 'status', 'expires_at'],
                'idx_entitlements_remaining_by_type'
            );
            $table->index(['status', 'expires_at'], 'idx_entitlements_status_expires');
        });

        if (DB::getDriverName() !== 'sqlite') {
            DB::statement(
                'ALTER TABLE entitlements ADD CONSTRAINT chk_ent_qty '
                . 'CHECK (qty_remaining >= 0 AND qty_total >= 0)'
            );
        }

        // --- entitlement_ledger (orig 100007 + booking_id col/index/FK from 100002 + chk) ---
        Schema::create('entitlement_ledger', function (Blueprint $table) {
            $table->id();
            $table->foreignId('entitlement_id')
                ->constrained('entitlements')
                ->cascadeOnDelete();
            $table->foreignId('member_id')
                ->constrained('members')
                ->restrictOnDelete();
            $table->integer('delta');
            $table->enum('reason', ['purchase', 'redeem', 'expire', 'refund', 'adjust']);
            $table->unsignedInteger('balance_after');
            $table->unsignedBigInteger('booking_id')->nullable(); // folded from 100002
            $table->foreignId('staff_id')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();
            $table->string('note', 255)->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->index(['entitlement_id', 'id'], 'idx_ledger_entitlement_id');
            $table->index(['member_id', 'created_at'], 'idx_ledger_member_created');
            $table->index(['reason', 'created_at'], 'idx_ledger_reason_created');
            $table->index('booking_id', 'idx_ledger_booking_id'); // folded from 100002
        });

        if (DB::getDriverName() !== 'sqlite') {
            DB::statement(
                'ALTER TABLE entitlement_ledger ADD CONSTRAINT chk_ledger_balance '
                . 'CHECK (balance_after >= 0)'
            );
            Schema::table('entitlement_ledger', function (Blueprint $table) {
                $table->foreign('booking_id', 'fk_ledger_booking_id')
                    ->references('id')
                    ->on('bookings')
                    ->nullOnDelete();
            });
        }
    }
};
