<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Branch context (§3.1). Scoping unit for redemption eligibility.
 * No FKs — this is the root reference table; everything else depends on it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('branches', function (Blueprint $table) {
            $table->id();
            $table->string('name', 120);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            // Unique: prevents duplicate branch names (§3.1).
            $table->unique('name', 'uq_branches_name');
            // Index: filter active branches in pickers (§3.1).
            $table->index('is_active', 'idx_branches_is_active');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('branches');
    }
};
