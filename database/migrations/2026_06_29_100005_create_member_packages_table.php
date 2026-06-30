<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Owned lot (§3.6). One row per purchase — the unit of per-lot expiry and the
 * parent of its entitlements. Financial record: branch_id and pricing are
 * snapshotted at sale (§5.1, §5.5). member_id is RESTRICT (protect the record —
 * members are soft-deleted, never hard-deleted, §5.4).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('member_packages', function (Blueprint $table) {
            $table->id();

            // Owner. RESTRICT: protect the financial record (§5.4).
            $table->foreignId('member_id')
                ->constrained('members')
                ->restrictOnDelete();

            // Source package (provenance hint only). SET NULL — keep the lot even
            // if the catalog row is removed (§3.6, §5.1).
            $table->foreignId('package_id')
                ->nullable()
                ->constrained('packages')
                ->nullOnDelete();

            // Snapshot of redemption scope. Null = any-branch. SET NULL on branch
            // delete (lot becomes any-branch — acceptable & snapshotted, §3.6/§5.5).
            $table->foreignId('branch_id')
                ->nullable()
                ->constrained('branches')
                ->nullOnDelete();

            $table->dateTime('purchased_at');
            $table->dateTime('expires_at')->nullable(); // per-lot; null = never
            $table->decimal('price_paid', 10, 2);       // THB actually paid (§5.6)
            $table->enum('status', ['active', 'expired', 'used_up'])->default('active');

            $table->timestamps();

            // I8: member's active lots list.
            $table->index(['member_id', 'status'], 'idx_member_packages_member_status');
            // I9: lot-level expiry scan.
            $table->index(['status', 'expires_at'], 'idx_member_packages_status_expires');
            // (branch_id index auto-created by foreignId()->constrained().)
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('member_packages');
    }
};
