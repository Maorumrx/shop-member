<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

/**
 * Owned per-item snapshot + read cache (§3.7). One row per package_line of the
 * purchased package. All item descriptor fields are SNAPSHOTTED at purchase
 * (§5.1); qty_remaining is a derived cache reconcilable from the ledger (§5.2).
 * member_id and expires_at are denormalized so the hot FIFO redemption query
 * (I1) needs no join.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('entitlements', function (Blueprint $table) {
            $table->id();

            // Parent lot. CASCADE — entitlements live and die with the lot (§3.7).
            $table->foreignId('member_package_id')
                ->constrained('member_packages')
                ->cascadeOnDelete();

            // Denormalized owner. RESTRICT — protect the ledger; member soft-delete
            // only (§3.7, §5.4).
            $table->foreignId('member_id')
                ->constrained('members')
                ->restrictOnDelete();

            // ---- SNAPSHOTS from package_lines (frozen at purchase) ----
            $table->string('item_code', 40);
            $table->string('item_name', 150);
            $table->enum('item_type', ['service', 'addon']);
            $table->unsignedInteger('qty_total');     // = ledger purchase delta
            $table->unsignedInteger('qty_remaining'); // READ CACHE = qty_total + Σ delta
            $table->string('redeem_group', 40)->nullable();
            $table->dateTime('expires_at')->nullable(); // snapshot of lot expiry; null = never

            $table->enum('status', ['active', 'expired', 'used_up'])->default('active');

            $table->timestamps();

            // I1 (critical): FIFO redemption composite.
            $table->index(
                ['member_id', 'item_code', 'status', 'expires_at', 'qty_remaining'],
                'idx_entitlements_fifo'
            );
            // I2 (aggregate): "remaining by type".
            $table->index(
                ['member_id', 'item_type', 'status', 'expires_at'],
                'idx_entitlements_remaining_by_type'
            );
            // I3: expiry job daily scan.
            $table->index(['status', 'expires_at'], 'idx_entitlements_status_expires');
            // I4: load all entitlements of a lot — satisfied by the auto-created
            // member_package_id FK index (foreignId()->constrained()); not re-declared.
        });

        // CHECK constraints (§3.7). MariaDB 11.4 enforces these; a double-spend /
        // bad-adjust surfaces as an error instead of wrapping the unsigned column.
        // Guarded so sqlite (used transiently during scaffold) doesn't choke.
        if (DB::getDriverName() !== 'sqlite') {
            DB::statement(
                'ALTER TABLE entitlements ADD CONSTRAINT chk_ent_qty '
                . 'CHECK (qty_remaining >= 0 AND qty_total >= 0)'
            );
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'sqlite') {
            DB::statement('ALTER TABLE entitlements DROP CONSTRAINT chk_ent_qty');
        }

        Schema::dropIfExists('entitlements');
    }
};
