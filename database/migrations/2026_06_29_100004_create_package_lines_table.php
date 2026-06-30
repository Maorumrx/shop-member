<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Catalog detail + add-on binding (§3.5). One row per line item of a package.
 * redeem_group (nullable) is the add-on coupling mechanism (§5.3): null =
 * independent line; shared non-null value = lines that redeem together.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('package_lines', function (Blueprint $table) {
            $table->id();

            // Owner package. CASCADE: lines belong wholly to the package
            // definition; deleting a draft package removes its lines (§3.5).
            // (Sold lots already snapshotted, so cascade is safe for owned data.)
            $table->foreignId('package_id')
                ->constrained('packages')
                ->cascadeOnDelete();

            $table->string('item_code', 40);   // stable business code
            $table->string('item_name', 150);  // human label
            $table->enum('item_type', ['service', 'addon'])->default('service');
            $table->unsignedInteger('qty');     // units granted per purchase
            $table->string('redeem_group', 40)->nullable(); // add-on coupling (§5.3)

            $table->timestamps();

            // I10: UNIQUE (package_id, item_code) — one logical item per package.
            $table->unique(['package_id', 'item_code'], 'uq_package_lines_pkg_item');
            // Resolve a redeem group within a package (§3.5 / §5.3).
            $table->index(['package_id', 'redeem_group'], 'idx_package_lines_pkg_group');
            // Note: foreignId()->constrained() auto-creates an index on package_id.
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('package_lines');
    }
};
