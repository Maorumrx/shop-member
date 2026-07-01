<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * member_link_codes (docs/member-line-linking-design.md §2, §6) — the short-lived
 * staff-generated CLAIM CODE that attaches a customer's LINE account to their
 * existing counter member row on first LINE login. A dedicated table (NOT columns
 * on `members`, §2) so the hot member row stays lean and the code carries its own
 * lifecycle (`consumed_at`) + audit (`created_by_user_id`, `consumed_by_line_user_id`)
 * + brute-force counter (`attempts`).
 *
 * The code is stored ONLY as a SHA-256 hex hash (`code_hash`) — the plaintext 6
 * digits exist momentarily at generation (shown to staff) and in the customer's
 * submit request, never at rest (§3). This is a credential/audit log, NOT
 * member/financial data, so it uses NO SoftDeletes — a code is live, consumed, or
 * expired; consumed/expired rows are retained (not hard-deleted) for the trail.
 *
 * FK on-delete (consistent with §9 conventions / bookings + member_packages):
 *   - member_id            → members RESTRICT  (never orphan the member the code
 *                            claims; members are soft-deleted only, §5.4)
 *   - created_by_user_id   → users SET NULL    (keep the audit row if the staff
 *                            account is later removed, mirroring bookings *_by_user_id)
 *
 * "ONE LIVE CODE PER MEMBER" (I20) — DECISION:
 *   The design (§6) sketched a generated `active_member_id` column with a UNIQUE
 *   index (many-NULLs → one live row per member) as a HARD DB guarantee. We
 *   DELIBERATELY DO NOT ship that here. A generated column referencing `consumed_at`
 *   combined with a UNIQUE that leans on driver-specific NULL semantics is fragile
 *   across our two drivers (sqlite test DB vs MariaDB prod) — and we were recently
 *   burned by a CHECK on a SET-NULL FK column failing on MariaDB (error 1901).
 *   Per the correctness-over-cleverness rule, the "single live code per member"
 *   invariant is enforced ENTIRELY at the SERVICE level:
 *   {@see \App\Services\Line\MemberLinkService::generate()} supersedes any live
 *   code (sets `consumed_at = now()`) and inserts the new one INSIDE ONE
 *   transaction under a `lockForUpdate` on the member row — so two concurrent
 *   generates serialize and only the newest code is ever live. No DB unique is
 *   added (it did not cleanly work on both drivers); the I22 (member_id, consumed_at)
 *   index backs the supersede scan + the admin "has a live code?" check.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('member_link_codes', function (Blueprint $table) {
            $table->id();

            // The counter member this code claims. RESTRICT — never orphan a
            // member (soft-deleted only, §5.4); a member with codes isn't
            // hard-deletable (irrelevant in practice since members never are).
            $table->foreignId('member_id')
                ->constrained('members')
                ->restrictOnDelete();

            // SHA-256 hex of the 6-digit code (64 chars). Never plaintext (§3).
            // The submit lookup hashes the typed digits and matches this column.
            $table->string('code_hash', 64);

            // Proposed 24h window (owner-tunable, §8). Submit checks expires_at > now().
            $table->dateTime('expires_at');

            // Per-code brute-force counter. Increments on every wrong entry against
            // a live code; at 5 the code is burned (consumed) (§3). MariaDB CHECK
            // below caps it 0..5; the SERVICE is the real gate.
            $table->unsignedTinyInteger('attempts')->default(0);

            // Set when the code is successfully used OR superseded by a regenerate
            // OR burned at 5 attempts. Non-null = dead (§3 single-use). This is the
            // column the "one live code" service invariant keys on (consumed_at IS NULL).
            $table->dateTime('consumed_at')->nullable();

            // The LINE `sub` that redeemed it — audit ("who claimed this"). Up to 64
            // to match the consumed_by width used elsewhere for a LINE user id.
            $table->string('consumed_by_line_user_id', 64)->nullable();

            // Staff who generated it. SET NULL on staff delete (keep the audit row),
            // mirroring the bookings/entitlement_ledger *_by_user_id convention (§9).
            $table->foreignId('created_by_user_id')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            $table->timestamps();

            // I21 (submit-code): resolve the code the customer typed → its live row.
            // WHERE code_hash = ? AND consumed_at IS NULL AND expires_at > now().
            $table->index(['code_hash', 'consumed_at'], 'idx_link_codes_hash_consumed');

            // I22 (generate/supersede + admin "has a live code?" badge):
            // WHERE member_id = ? AND consumed_at IS NULL.
            $table->index(['member_id', 'consumed_at'], 'idx_link_codes_member_consumed');
            // (created_by_user_id FK auto-index created by foreignId()->constrained();
            //  member_id FK auto-index is redundant with I22's leading column — harmless.)
        });

        // CHECK constraint (§6). MariaDB enforces the attempts domain; guarded so the
        // sqlite test DB doesn't choke, mirroring chk_bookings_range / chk_ent_qty.
        // NOTE: this CHECK is on `attempts` (a plain unsignedTinyInteger), NOT on a
        // SET-NULL FK column — so it does NOT hit the error-1901 trap that forced us
        // to drop the bookings origin CHECK.
        if (DB::getDriverName() !== 'sqlite') {
            DB::statement(
                'ALTER TABLE member_link_codes ADD CONSTRAINT chk_link_codes_attempts '
                . 'CHECK (attempts >= 0 AND attempts <= 5)'
            );
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'sqlite') {
            DB::statement('ALTER TABLE member_link_codes DROP CONSTRAINT chk_link_codes_attempts');
        }

        Schema::dropIfExists('member_link_codes');
    }
};
