<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * bookings (docs/phase7-booking-design.md §3.2, P7-2) — a member's reservation of
 * a branch time-slot for an intended service. Scheduling/operational data, NOT
 * financial: a booking holds NO entitlement and writes NO ledger row on create.
 * Redemption happens at CHECK-IN via the existing RedemptionService, which stamps
 * the resulting ledger rows with this booking's id (§1, §7).
 *
 * TWO CLIENT DECISIONS applied over the design doc:
 *   1. Slot length is FIXED per branch (branch_booking_settings.slot_length_minutes);
 *      there is no per-service duration. The length is still SNAPSHOTTED onto each
 *      booking so a later config change can't mutate existing bookings.
 *   2. AUTO-CONFIRM: a booking is created directly as `confirmed` (creation IS
 *      confirmation), so the `pending` status is DROPPED from v1 and the
 *      `confirmed_at`/`confirmed_by_user_id` audit trio is removed (created_at IS
 *      the confirmation time). Only the elapsed-`confirmed`→`no_show` sweep remains.
 *
 * FK on-delete (consistent with Phase-1, §3.2):
 *   - member_id  → members RESTRICT  (never orphan/vanish a member; §5.4)
 *   - branch_id  → branches RESTRICT (a branch with bookings isn't silently deletable)
 *   - *_by_user_id → users SET NULL  (keep the booking if a staff account is removed)
 *
 * Soft-deleted so a mis-booked/duplicate row leaves the active views without
 * losing history — and a checked-in/completed booking is referenced by ledger
 * rows via booking_id, so it must never be hard-deleted (§3.2).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bookings', function (Blueprint $table) {
            $table->id();

            // Who the booking is FOR (self, or staff-created for this member).
            // RESTRICT — protect the record; members are soft-deleted only (§3.2).
            $table->foreignId('member_id')
                ->constrained('members')
                ->restrictOnDelete();

            // Where. RESTRICT — a branch on the calendar isn't silently deletable
            // (retire via branches.is_active / settings.is_bookable instead, §3.2).
            $table->foreignId('branch_id')
                ->constrained('branches')
                ->restrictOnDelete();

            // ---- INTENDED service ----
            // item_code: the code RedemptionService.redeem() consumes at check-in.
            // Same width/domain as entitlements.item_code. Stored as a code (not an
            // entitlement FK) precisely because the member may not own it yet (§3.2).
            $table->string('item_code', 40);
            // SNAPSHOT label at booking time (catalog may rename/delete; §3.2, §5.1).
            $table->string('item_name', 150);

            // Slot anchor. scheduled_end is DERIVED & STORED (= start + snapshotted
            // length) so capacity/day queries are plain datetime range scans (§3.2).
            $table->dateTime('scheduled_start');
            $table->dateTime('scheduled_end');

            // SNAPSHOT of the branch's slot_length_minutes at booking time, so a
            // later config change never mutates an existing booking's length (§3.2).
            $table->unsignedSmallInteger('slot_length_minutes');

            // v1 auto-confirm: new bookings land as `confirmed`. `pending` dropped.
            $table->enum('status', ['confirmed', 'checked_in', 'completed', 'cancelled', 'no_show'])
                ->default('confirmed');

            // WHO created it (the two-guard origin). member = LIFF self-booking;
            // staff = counter/admin (§3.2).
            $table->enum('created_via', ['member', 'staff']);

            // users.id when created_via=staff; NULL when the member self-booked
            // (no users row for a member). SET NULL on staff-account delete (§3.2).
            $table->foreignId('created_by_user_id')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            // ---- Lifecycle audit (creation IS confirmation, so no confirmed_* pair) ----
            // Set on →checked_in (arrival) — the moment redemption runs.
            $table->dateTime('checked_in_at')->nullable();
            $table->foreignId('checked_in_by_user_id')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            // Set on →completed (redemption succeeded; terminal success).
            $table->dateTime('completed_at')->nullable();

            // Set on →cancelled. cancelled_by_user_id NULL = member self-cancel /
            // system sweep; a user id = staff cancel (§3.2).
            $table->dateTime('cancelled_at')->nullable();
            $table->foreignId('cancelled_by_user_id')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            // Free text (member request / staff remark). Matches ledger.note width.
            $table->string('note', 255)->nullable();

            // Soft delete — mis-booked/duplicate rows leave active views without
            // losing the ledger back-reference (§3.2).
            $table->softDeletes();
            $table->timestamps();

            // I14 (critical): the capacity count + branch day-view.
            $table->index(
                ['branch_id', 'scheduled_start', 'status'],
                'idx_bookings_branch_slot_status'
            );
            // I15: a member's upcoming/own bookings by time.
            $table->index(['member_id', 'scheduled_start'], 'idx_bookings_member_start');
            // I16: no-show sweep scan (status + elapsed end). Mirrors the Phase-1
            // expiry-scan index shape (status, expires_at).
            $table->index(['status', 'scheduled_end'], 'idx_bookings_status_end');
            // (branch_id/member_id/*_by_user_id FK auto-indexes created by
            //  foreignId()->constrained(); member_id also leads I15.)
        });

        // CHECK constraints (§3.2). MariaDB enforces; guarded so sqlite doesn't
        // choke, mirroring the Phase-1 migrations.
        if (DB::getDriverName() !== 'sqlite') {
            // Slot must have positive duration.
            DB::statement(
                'ALTER TABLE bookings ADD CONSTRAINT chk_bookings_range '
                . 'CHECK (scheduled_end > scheduled_start)'
            );
            // NOTE: the origin-consistency CHECK (staff ⇒ created_by_user_id present;
            // member ⇒ null) was REMOVED — MariaDB rejects it (error 1901) because
            // `created_by_user_id` is a `SET NULL` foreign key: deleting a staff
            // account nulls the column, which would violate the CHECK for a
            // staff-origin row. SET NULL is the correct FK behaviour (keep the
            // booking if the staff user is removed), so the invariant is enforced at
            // WRITE time by BookingService + the StoreBookingRequest instead.
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'sqlite') {
            DB::statement('ALTER TABLE bookings DROP CONSTRAINT chk_bookings_range');
        }

        Schema::dropIfExists('bookings');
    }
};
