<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Per-branch slot config (docs/phase7-booking-design.md §3.1, P7-1). One row per
 * bookable branch (1:1 with `branches`, UNIQUE `branch_id` = I18). Kept as a
 * SEPARATE table so the hot `branches` reference row stays lean and a
 * non-bookable branch simply has no row (§3.1).
 *
 * `is_bookable` is the master switch; `slot_capacity`, `slot_length_minutes`,
 * `open_time`, `close_time`, `max_advance_days` define the uniform daily slot
 * grid. FK CASCADE — this config is meaningless without its branch and carries
 * no financial data (unlike member-owned rows), so cascading on branch delete is
 * safe (§3.1).
 *
 * This settings row also doubles as the per-branch MUTEX for concurrency-safe
 * booking creation: BookingService locks it FOR UPDATE before counting a slot
 * (§5.4 Strategy A), serialising concurrent creates for the branch.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('branch_booking_settings', function (Blueprint $table) {
            $table->id();

            // Owning branch. CASCADE — config dies with its branch; carries no
            // financial data, safe to cascade (§3.1).
            $table->foreignId('branch_id')
                ->constrained('branches')
                ->cascadeOnDelete();

            // Master switch: false = branch takes no bookings (§3.1).
            $table->boolean('is_bookable')->default(false);

            // Concurrent massages the branch can run (rooms/therapists). A slot is
            // full when confirmed+checked_in bookings reach this (§3.1, §5.2).
            $table->unsignedSmallInteger('slot_capacity')->default(1);

            // Fixed slot length; also snapshotted onto each booking (§3.1).
            $table->unsignedSmallInteger('slot_length_minutes')->default(60);

            // Daily window: first slot starts at/after open_time; last slot must
            // END at/before close_time (§3.1).
            $table->time('open_time');
            $table->time('close_time');

            // How far ahead a member may book (0 = today only; app-enforced, §3.1).
            $table->unsignedSmallInteger('max_advance_days')->default(30);

            $table->timestamps();

            // I18: UNIQUE branch_id — 1:1 config lookup + the per-branch lock
            // target (§5.4). Doubles as the lookup index; no others needed.
            $table->unique('branch_id', 'uq_branch_booking_settings_branch');
        });

        // CHECK constraints (§3.1). MariaDB enforces these; guarded so sqlite
        // (used transiently during scaffold) doesn't choke, mirroring the Phase-1
        // migrations.
        if (DB::getDriverName() !== 'sqlite') {
            DB::statement(
                'ALTER TABLE branch_booking_settings ADD CONSTRAINT chk_bbs_capacity '
                . 'CHECK (slot_capacity >= 1)'
            );
            DB::statement(
                'ALTER TABLE branch_booking_settings ADD CONSTRAINT chk_bbs_length '
                . 'CHECK (slot_length_minutes >= 1)'
            );
            DB::statement(
                'ALTER TABLE branch_booking_settings ADD CONSTRAINT chk_bbs_advance '
                . 'CHECK (max_advance_days >= 0)'
            );
            DB::statement(
                'ALTER TABLE branch_booking_settings ADD CONSTRAINT chk_bbs_window '
                . 'CHECK (close_time > open_time)'
            );
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'sqlite') {
            DB::statement('ALTER TABLE branch_booking_settings DROP CONSTRAINT chk_bbs_capacity');
            DB::statement('ALTER TABLE branch_booking_settings DROP CONSTRAINT chk_bbs_length');
            DB::statement('ALTER TABLE branch_booking_settings DROP CONSTRAINT chk_bbs_advance');
            DB::statement('ALTER TABLE branch_booking_settings DROP CONSTRAINT chk_bbs_window');
        }

        Schema::dropIfExists('branch_booking_settings');
    }
};
