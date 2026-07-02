<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * topup_offers — presets for the top-up sell screen (NEW). Each row is a
 * one-tap "pay `amount` → get `amount + bonus` spendable" button
 * (e.g. pay 10,000 → +1,000 bonus; pay 5,000 → +500). Mutable config: changing an
 * offer NEVER touches already-sold `credit_lots` (a lot snapshots amount_paid /
 * bonus_amount at sale), exactly like the dropped `packages` vs sold lots split.
 *
 * A CUSTOM amount + bonus is entered ad-hoc at the POS and is NOT stored here — this
 * table is only the quick-pick list. Both columns are decimal(10,2), NEVER float (§5.6).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('topup_offers', function (Blueprint $table) {
            $table->id();

            $table->string('name', 150);

            // Cash the customer pays. NEVER float (§5.6).
            $table->decimal('amount', 10, 2);

            // Promotional bonus added on top; 0 = no bonus. Tracked separately from
            // paid money all the way down the ledger (refunds return paid only).
            $table->decimal('bonus', 10, 2)->default(0);

            $table->boolean('is_active')->default(true);

            // Display order on the sell screen (low → high).
            $table->integer('sort_order')->default(0);

            $table->timestamps();

            // Active presets in display order — the sell-screen list query.
            $table->index(['is_active', 'sort_order'], 'idx_topup_offers_active_sort');
        });

        // CHECK: no negative money. Guarded so sqlite doesn't choke.
        if (DB::getDriverName() !== 'sqlite') {
            DB::statement(
                'ALTER TABLE topup_offers ADD CONSTRAINT chk_topup_offers_amounts '
                . 'CHECK (amount >= 0 AND bonus >= 0)'
            );
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'sqlite') {
            DB::statement('ALTER TABLE topup_offers DROP CONSTRAINT chk_topup_offers_amounts');
        }

        Schema::dropIfExists('topup_offers');
    }
};
