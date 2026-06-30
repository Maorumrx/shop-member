<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Catalog: sellable package definitions (§3.4). Mutable marketing/config data.
 * Editing a package NEVER touches sold lots (lots snapshot at purchase, §5.1).
 * branch_id null = redeemable at ANY branch; set = that branch only.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('packages', function (Blueprint $table) {
            $table->id();
            $table->string('name', 150);

            // THB list price. NEVER float (§5.6).
            $table->decimal('price', 10, 2);

            // Null = never expires → sold lot gets expires_at = null (§3.4).
            $table->unsignedInteger('valid_days')->nullable();

            // Null = redeemable at ANY branch; set = that branch only.
            // RESTRICT on delete: a branch with packages bound to it cannot be
            // silently deleted — force reassign/deactivate first (§3.4).
            $table->foreignId('branch_id')
                ->nullable()
                ->constrained('branches')
                ->restrictOnDelete();

            $table->boolean('is_active')->default(true);
            $table->timestamps();

            // Indexes (§3.4 / §4): hide-from-sale filter + branch scoping.
            $table->index('is_active', 'idx_packages_is_active');
            // Note: foreignId()->constrained() auto-creates an index on branch_id.
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('packages');
    }
};
