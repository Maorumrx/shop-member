<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Add `bookings.reminded_at` — the dedup marker for the LINE upcoming-booking
 * reminder (bookings:remind). Nullable datetime, stamped the moment a reminder
 * push is queued so the hourly command is IDEMPOTENT: a booking is reminded at
 * most once (WHERE reminded_at IS NULL). No CHECK/index needed — the command
 * already narrows on status + scheduled_start (I15/I16) and reminded_at only
 * gates within that already-tight set.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            // Placed after the completion audit; null until the reminder is queued.
            $table->dateTime('reminded_at')->nullable()->after('completed_at');
        });
    }

    public function down(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            $table->dropColumn('reminded_at');
        });
    }
};
