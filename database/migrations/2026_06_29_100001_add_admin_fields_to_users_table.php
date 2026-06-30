<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Admin guard fields (§3.2). ALTERs the scaffold's existing `users` table.
 * The base `users` table (id, name, email UK, email_verified_at, password,
 * remember_token, timestamps) is created by Laravel's 0001_01_01_* migration.
 * Here we only add: role enum (owner|staff), nullable branch_id FK (SET NULL),
 * is_active. We do NOT create the table.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // Authorization role (§3.2). Placed after password for readability.
            $table->enum('role', ['owner', 'staff'])->default('staff')->after('password');

            // Home branch of staff; null = unscoped/owner. SET NULL so deleting
            // a branch never deletes staff (§3.2). I-ref: index (branch_id).
            $table->foreignId('branch_id')
                ->nullable()
                ->after('role')
                ->constrained('branches')
                ->nullOnDelete();

            // Disable login without delete (§3.2).
            $table->boolean('is_active')->default(true)->after('branch_id');

            // Index (role) for authorization filtering (§3.2).
            $table->index('role', 'idx_users_role');
            // Note: `email` UNIQUE (I13) already exists from the scaffold migration.
            // Note: foreignId()->constrained() auto-creates an index on branch_id.
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // Drop FK + its auto-created index before dropping the column.
            $table->dropForeign(['branch_id']);
            $table->dropIndex('idx_users_role');
            $table->dropColumn(['role', 'branch_id', 'is_active']);
        });
    }
};
