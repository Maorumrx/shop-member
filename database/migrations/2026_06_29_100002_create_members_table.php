<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Member guard (§3.3). Supports BOTH LINE self-register and admin-created
 * accounts. `line_user_id` is nullable+unique so an admin can create a member
 * first and link LINE later (MySQL/MariaDB allows multiple NULLs in a UNIQUE
 * index). Members are NEVER hard-deleted — softDeletes protect the financial
 * ledger (§5.4); all child FKs to members use restrictOnDelete.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('members', function (Blueprint $table) {
            $table->id();

            // LINE sub/userId. Nullable + UNIQUE (link later) — I12. (§3.3)
            $table->string('line_user_id', 64)->nullable();

            $table->string('name', 120);
            $table->string('phone', 20)->nullable();   // counter lookup — I11
            $table->string('email', 190)->nullable();
            $table->string('avatar_url', 512)->nullable();

            // Home branch hint (not enforcement). SET NULL on branch delete (§3.3).
            $table->foreignId('default_branch_id')
                ->nullable()
                ->constrained('branches')
                ->nullOnDelete();

            // Nullable — LINE members have no password; counter accounts may (§3.3).
            $table->string('password', 255)->nullable();

            $table->boolean('is_active')->default(true);
            $table->rememberToken();

            // Soft delete — members never hard-deleted, protects the ledger (§5.4).
            $table->softDeletes();
            $table->timestamps();

            // I12: UNIQUE line_user_id — LINE login resolution + link-later guard.
            $table->unique('line_user_id', 'uq_members_line_user_id');
            // I11: counter lookup by phone.
            $table->index('phone', 'idx_members_phone');
            // Note: foreignId()->constrained() auto-creates an index on default_branch_id.
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('members');
    }
};
