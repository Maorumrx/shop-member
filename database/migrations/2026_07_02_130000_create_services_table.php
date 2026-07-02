<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * services — the per-service baht PRICE LIST (NEW; no equivalent existed under the
 * package model). The credit-wallet debit-at-check-in path needs a price for the
 * `item_code` a member is consuming: DEBIT = look up services.price for the item,
 * subtract it from the wallet (credit_lots FIFO), append credit_ledger rows.
 *
 * Mutable reference/catalog data (like the dropped `packages`): editing a price
 * NEVER rewrites past debits — every credit_ledger debit row records the exact
 * baht taken at the time, so a later price change can't mutate history.
 *
 * `item_code` is the same stable business code / width (40) the dropped
 * entitlements + kept `bookings.item_code` use, so a booking's intended item maps
 * straight to a priced service. It is GLOBALLY UNIQUE: one canonical price per
 * item. `branch_id` is an optional scope hint (null = offered/priced at ANY
 * branch); it does not multiply the price per branch (that would need a non-unique
 * item_code — deferred until the client actually prices per branch).
 *
 * FK on-delete: branch_id → branches SET NULL (a deleted branch demotes the
 * service to any-branch). services is a lightweight non-financial price list, so
 * — unlike the dropped catalog `packages` (RESTRICT) — losing the branch scope on
 * the rare branch hard-delete is acceptable and keeps the debit path resolvable.
 * Branches are normally retired via `is_active`, not deleted.
 *
 * Money is decimal(10,2), NEVER float (§5.6).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('services', function (Blueprint $table) {
            $table->id();

            // Stable business code the debit path + bookings consume. UNIQUE.
            $table->string('item_code', 40);

            // Human label (same width as the dropped entitlements.item_name).
            $table->string('name', 150);

            // THB price debited per visit. NEVER float (§5.6).
            $table->decimal('price', 10, 2);

            // Optional scope. Null = any-branch price. SET NULL on branch delete.
            $table->foreignId('branch_id')
                ->nullable()
                ->constrained('branches')
                ->nullOnDelete();

            $table->boolean('is_active')->default(true);
            $table->timestamps();

            // One canonical price row per business code.
            $table->unique('item_code', 'uq_services_item_code');
            // Hide-from-list filter on the sell/check-in screens.
            $table->index('is_active', 'idx_services_is_active');
            // (branch_id index auto-created by foreignId()->constrained().)
        });

        // CHECK: no negative prices. Guarded so sqlite doesn't choke (mirrors the
        // Phase-1 migrations' pattern).
        if (DB::getDriverName() !== 'sqlite') {
            DB::statement(
                'ALTER TABLE services ADD CONSTRAINT chk_services_price CHECK (price >= 0)'
            );
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'sqlite') {
            DB::statement('ALTER TABLE services DROP CONSTRAINT chk_services_price');
        }

        Schema::dropIfExists('services');
    }
};
