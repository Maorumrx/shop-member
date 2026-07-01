<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Add `member_packages.expiry_reminded_at` — the dedup marker for the LINE
 * near-expiry reminder (members:remind-expiry). Nullable datetime, stamped the
 * moment a near-expiry push is queued so the daily command is IDEMPOTENT: a lot
 * is reminded at most once (WHERE expiry_reminded_at IS NULL). No CHECK/index
 * needed — the command narrows on status + expires_at (rides I9) and this column
 * only gates within that already-tight set.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('member_packages', function (Blueprint $table) {
            // Placed after expires_at (the value it tracks); null until queued.
            $table->dateTime('expiry_reminded_at')->nullable()->after('expires_at');
        });
    }

    public function down(): void
    {
        Schema::table('member_packages', function (Blueprint $table) {
            $table->dropColumn('expiry_reminded_at');
        });
    }
};
