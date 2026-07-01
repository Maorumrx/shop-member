<?php

declare(strict_types=1);

// Phase — LINE push notifications. Reminder commands:
//   - bookings:remind — confirmed bookings starting in (now, now+24h], reminded_at
//     NULL, member LINE-linked → queue a reminder + stamp reminded_at. IDEMPOTENT.
//   - members:remind-expiry — active lots expiring in (now, now+7d], positive
//     remaining, member LINE-linked, expiry_reminded_at NULL → queue + stamp.
//     IDEMPOTENT.
// Queue::fake() proves the SendLineMessage dispatch (and its absence) without
// running inline; DB state proves the stamping. Never hits LINE.
// See App\Console\Commands\RemindBookings + RemindExpiring, App\Services\Line\MemberNotifier.

use App\Enums\BookingOrigin;
use App\Enums\BookingStatus;
use App\Enums\EntitlementStatus;
use App\Enums\ItemType;
use App\Enums\LedgerReason;
use App\Jobs\SendLineMessage;
use App\Models\Booking;
use App\Models\Branch;
use App\Models\Entitlement;
use App\Models\Member;
use App\Models\MemberPackage;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

/** Fixed "now" so the (now, now+24h] / (now, now+7d] windows are deterministic. */
function reminderNow(): CarbonImmutable
{
    return CarbonImmutable::parse('2026-08-03 10:00:00');
}

function reminderBranch(): Branch
{
    return Branch::create(['name' => 'Reminder Branch', 'is_active' => true]);
}

/**
 * A real member — LINE-linked when $lineUserId is given, otherwise unlinked.
 *
 * @param  array<string, mixed>  $overrides
 */
function reminderMember(?string $lineUserId, array $overrides = []): Member
{
    static $seq = 0;
    $seq++;

    return Member::create(array_merge([
        'line_user_id' => $lineUserId,
        'name' => 'Reminder Member',
        'phone' => '08800000' . str_pad((string) $seq, 2, '0', STR_PAD_LEFT),
        'is_active' => true,
    ], $overrides));
}

/**
 * A confirmed booking for $member at $start.
 *
 * @param  array<string, mixed>  $overrides
 */
function reminderBooking(Member $member, int $branchId, CarbonImmutable $start, array $overrides = []): Booking
{
    return Booking::create(array_merge([
        'member_id' => $member->id,
        'branch_id' => $branchId,
        'item_code' => 'MASSAGE_60',
        'item_name' => 'MASSAGE_60',
        'scheduled_start' => $start,
        'scheduled_end' => $start->addMinutes(60),
        'slot_length_minutes' => 60,
        'status' => BookingStatus::Confirmed,
        'created_via' => BookingOrigin::Member,
        'created_by_user_id' => null,
        'note' => null,
    ], $overrides));
}

/**
 * An active lot for $member expiring at $expiresAt, holding one active entitlement
 * with $remaining redeemable units.
 *
 * @param  array<string, mixed>  $overrides
 */
function reminderLot(Member $member, CarbonImmutable $expiresAt, int $remaining, array $overrides = []): MemberPackage
{
    $lot = MemberPackage::create(array_merge([
        'member_id' => $member->id,
        'package_id' => null,
        'branch_id' => null,
        'purchased_at' => reminderNow()->subDay(),
        'expires_at' => $expiresAt,
        'price_paid' => '0.00',
        'status' => EntitlementStatus::Active,
    ], $overrides));

    $ent = Entitlement::create([
        'member_package_id' => $lot->id,
        'member_id' => $member->id,
        'item_code' => 'MASSAGE_60',
        'item_name' => 'MASSAGE_60',
        'item_type' => ItemType::Service,
        'qty_total' => max($remaining, 1),
        'qty_remaining' => $remaining,
        'redeem_group' => null,
        'expires_at' => $expiresAt,
        'status' => EntitlementStatus::Active,
    ]);

    $ent->ledgerEntries()->create([
        'member_id' => $member->id,
        'delta' => max($remaining, 1),
        'reason' => LedgerReason::Purchase,
        'balance_after' => $remaining,
        'booking_id' => null,
        'staff_id' => null,
        'note' => null,
    ]);

    return $lot;
}

beforeEach(function () {
    $this->travelTo(reminderNow());
});

// ===========================================================================
// bookings:remind
// ===========================================================================

it('reminds a due, LINE-linked, not-yet-reminded confirmed booking and stamps reminded_at', function () {
    Queue::fake();

    $branch = reminderBranch();
    $member = reminderMember('U5000000000000000000000000000001');
    // Starts ~2h from now — inside (now, now+24h].
    $booking = reminderBooking($member, $branch->id, reminderNow()->addHours(2));

    $this->artisan('bookings:remind')->assertSuccessful();

    expect($booking->fresh()->reminded_at)->not->toBeNull();
    Queue::assertPushed(SendLineMessage::class, 1);
});

it('does NOT remind a booking starting outside the 24h window', function () {
    Queue::fake();

    $branch = reminderBranch();
    $member = reminderMember('U5000000000000000000000000000002');
    // Starts in ~2 days — beyond now+24h.
    $booking = reminderBooking($member, $branch->id, reminderNow()->addDays(2));

    $this->artisan('bookings:remind')->assertSuccessful();

    expect($booking->fresh()->reminded_at)->toBeNull();
    Queue::assertNothingPushed();
});

it('does NOT re-remind a booking already stamped with reminded_at', function () {
    Queue::fake();

    $branch = reminderBranch();
    $member = reminderMember('U5000000000000000000000000000003');
    $booking = reminderBooking($member, $branch->id, reminderNow()->addHours(2), [
        'reminded_at' => reminderNow()->subHour(),
    ]);
    $stampedAt = $booking->fresh()->reminded_at;

    $this->artisan('bookings:remind')->assertSuccessful();

    // Untouched stamp, no new push.
    expect($booking->fresh()->reminded_at->equalTo($stampedAt))->toBeTrue();
    Queue::assertNothingPushed();
});

it('does NOT remind a due booking whose member is not LINE-linked', function () {
    Queue::fake();

    $branch = reminderBranch();
    $member = reminderMember(null); // no line_user_id
    $booking = reminderBooking($member, $branch->id, reminderNow()->addHours(2));

    $this->artisan('bookings:remind')->assertSuccessful();

    // Never queue an undeliverable push; the row is left un-stamped.
    expect($booking->fresh()->reminded_at)->toBeNull();
    Queue::assertNothingPushed();
});

it('does not double-remind a booking on a second run (idempotent)', function () {
    Queue::fake();

    $branch = reminderBranch();
    $member = reminderMember('U5000000000000000000000000000004');
    reminderBooking($member, $branch->id, reminderNow()->addHours(2));

    $this->artisan('bookings:remind')->assertSuccessful();
    $this->artisan('bookings:remind')->assertSuccessful();

    // First run reminded + stamped; the second run finds it already stamped.
    Queue::assertPushed(SendLineMessage::class, 1);
});

// ===========================================================================
// members:remind-expiry
// ===========================================================================

it('reminds a near-expiry, LINE-linked, positive-balance active lot and stamps expiry_reminded_at', function () {
    Queue::fake();

    $member = reminderMember('U6000000000000000000000000000001');
    // Expires in ~3 days — inside (now, now+7d].
    $lot = reminderLot($member, reminderNow()->addDays(3), remaining: 4);

    $this->artisan('members:remind-expiry')->assertSuccessful();

    expect($lot->fresh()->expiry_reminded_at)->not->toBeNull();
    Queue::assertPushed(SendLineMessage::class, 1);
});

it('does NOT remind a lot expiring beyond the 7-day horizon', function () {
    Queue::fake();

    $member = reminderMember('U6000000000000000000000000000002');
    // Expires in ~30 days — beyond now+7d.
    $lot = reminderLot($member, reminderNow()->addDays(30), remaining: 4);

    $this->artisan('members:remind-expiry')->assertSuccessful();

    expect($lot->fresh()->expiry_reminded_at)->toBeNull();
    Queue::assertNothingPushed();
});

it('does NOT re-remind a lot already stamped with expiry_reminded_at', function () {
    Queue::fake();

    $member = reminderMember('U6000000000000000000000000000003');
    $lot = reminderLot($member, reminderNow()->addDays(3), remaining: 4, overrides: [
        'expiry_reminded_at' => reminderNow()->subDay(),
    ]);
    $stampedAt = $lot->fresh()->expiry_reminded_at;

    $this->artisan('members:remind-expiry')->assertSuccessful();

    expect($lot->fresh()->expiry_reminded_at->equalTo($stampedAt))->toBeTrue();
    Queue::assertNothingPushed();
});

it('does NOT remind a near-expiry lot whose member is not LINE-linked', function () {
    Queue::fake();

    $member = reminderMember(null); // no line_user_id
    $lot = reminderLot($member, reminderNow()->addDays(3), remaining: 4);

    $this->artisan('members:remind-expiry')->assertSuccessful();

    expect($lot->fresh()->expiry_reminded_at)->toBeNull();
    Queue::assertNothingPushed();
});

it('does NOT remind a near-expiry lot with zero remaining balance', function () {
    Queue::fake();

    $member = reminderMember('U6000000000000000000000000000004');
    // Nothing left to lose → nothing to nudge about.
    $lot = reminderLot($member, reminderNow()->addDays(3), remaining: 0);

    $this->artisan('members:remind-expiry')->assertSuccessful();

    expect($lot->fresh()->expiry_reminded_at)->toBeNull();
    Queue::assertNothingPushed();
});

it('does not double-remind a lot on a second run (idempotent)', function () {
    Queue::fake();

    $member = reminderMember('U6000000000000000000000000000005');
    reminderLot($member, reminderNow()->addDays(3), remaining: 4);

    $this->artisan('members:remind-expiry')->assertSuccessful();
    $this->artisan('members:remind-expiry')->assertSuccessful();

    Queue::assertPushed(SendLineMessage::class, 1);
});
