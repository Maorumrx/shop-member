<?php

declare(strict_types=1);

// LINE push reminder commands:
//   - bookings:remind — confirmed bookings starting in (now, now+24h], reminded_at NULL,
//     member LINE-linked → queue a reminder + stamp reminded_at. IDEMPOTENT.
//   - members:remind-expiry — active CREDIT LOTS expiring in (now, now+7d] with a
//     positive remaining balance (paid_remaining + bonus_remaining > 0), member
//     LINE-linked, expiry_reminded_at NULL → queue + stamp. IDEMPOTENT. (The money-wallet
//     reframe of the dropped MemberPackage scan.)
// Queue::fake() proves the SendLineMessage dispatch (and its absence); DB state proves
// stamping. Never hits LINE. Lots that hold value are seeded via WalletService::topUp
// so the ledger stays honest; only the zero-balance edge is built directly to exercise
// the command's `remaining > 0` WHERE filter independent of status.

use App\Enums\BookingOrigin;
use App\Enums\BookingStatus;
use App\Enums\CreditLotStatus;
use App\Enums\CreditSource;
use App\Jobs\SendLineMessage;
use App\Models\Booking;
use App\Models\Branch;
use App\Models\CreditLot;
use App\Models\Member;
use App\Services\Wallet\WalletService;
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
        'phone' => '08800000'.str_pad((string) $seq, 2, '0', STR_PAD_LEFT),
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
 * An active credit lot for $member expiring at $expiresAt, holding $remaining paid
 * value — seeded honestly through the money authority.
 */
function reminderLot(Member $member, CarbonImmutable $expiresAt, string $remaining): CreditLot
{
    return app(WalletService::class)->topUp($member, $remaining, '0.00', CreditSource::Topup, null, null, $expiresAt);
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
    $booking = reminderBooking($member, $branch->id, reminderNow()->addHours(2));

    $this->artisan('bookings:remind')->assertSuccessful();

    expect($booking->fresh()->reminded_at)->not->toBeNull();
    Queue::assertPushed(SendLineMessage::class, 1);
});

it('does NOT remind a booking starting outside the 24h window', function () {
    Queue::fake();

    $branch = reminderBranch();
    $member = reminderMember('U5000000000000000000000000000002');
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

    expect($booking->fresh()->reminded_at->equalTo($stampedAt))->toBeTrue();
    Queue::assertNothingPushed();
});

it('does NOT remind a due booking whose member is not LINE-linked', function () {
    Queue::fake();

    $branch = reminderBranch();
    $member = reminderMember(null);
    $booking = reminderBooking($member, $branch->id, reminderNow()->addHours(2));

    $this->artisan('bookings:remind')->assertSuccessful();

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

    Queue::assertPushed(SendLineMessage::class, 1);
});

// ===========================================================================
// members:remind-expiry
// ===========================================================================

it('reminds a near-expiry, LINE-linked, positive-balance active lot and stamps expiry_reminded_at', function () {
    Queue::fake();

    $member = reminderMember('U6000000000000000000000000000001');
    $lot = reminderLot($member, reminderNow()->addDays(3), '400.00');

    $this->artisan('members:remind-expiry')->assertSuccessful();

    expect($lot->fresh()->expiry_reminded_at)->not->toBeNull();
    Queue::assertPushed(SendLineMessage::class, 1);
});

it('does NOT remind a lot expiring beyond the 7-day horizon', function () {
    Queue::fake();

    $member = reminderMember('U6000000000000000000000000000002');
    $lot = reminderLot($member, reminderNow()->addDays(30), '400.00');

    $this->artisan('members:remind-expiry')->assertSuccessful();

    expect($lot->fresh()->expiry_reminded_at)->toBeNull();
    Queue::assertNothingPushed();
});

it('does NOT re-remind a lot already stamped with expiry_reminded_at', function () {
    Queue::fake();

    $member = reminderMember('U6000000000000000000000000000003');
    $lot = reminderLot($member, reminderNow()->addDays(3), '400.00');
    $lot->update(['expiry_reminded_at' => reminderNow()->subDay()]);
    $stampedAt = $lot->fresh()->expiry_reminded_at;

    $this->artisan('members:remind-expiry')->assertSuccessful();

    expect($lot->fresh()->expiry_reminded_at->equalTo($stampedAt))->toBeTrue();
    Queue::assertNothingPushed();
});

it('does NOT remind a near-expiry lot whose member is not LINE-linked', function () {
    Queue::fake();

    $member = reminderMember(null);
    $lot = reminderLot($member, reminderNow()->addDays(3), '400.00');

    $this->artisan('members:remind-expiry')->assertSuccessful();

    expect($lot->fresh()->expiry_reminded_at)->toBeNull();
    Queue::assertNothingPushed();
});

it('does NOT remind a near-expiry active lot with zero remaining balance', function () {
    Queue::fake();

    $member = reminderMember('U6000000000000000000000000000004');
    // Directly build an active-but-drained lot to exercise the `remaining > 0` filter
    // independent of status (the service never produces this; it flips to used_up at 0).
    $lot = CreditLot::create([
        'member_id' => $member->id,
        'source' => CreditSource::Topup,
        'amount_paid' => '400.00',
        'bonus_amount' => '0.00',
        'paid_remaining' => '0.00',
        'bonus_remaining' => '0.00',
        'expires_at' => reminderNow()->addDays(3),
        'status' => CreditLotStatus::Active,
        'purchased_at' => reminderNow()->subDay(),
    ]);

    $this->artisan('members:remind-expiry')->assertSuccessful();

    expect($lot->fresh()->expiry_reminded_at)->toBeNull();
    Queue::assertNothingPushed();
});

it('does not double-remind a lot on a second run (idempotent)', function () {
    Queue::fake();

    $member = reminderMember('U6000000000000000000000000000005');
    reminderLot($member, reminderNow()->addDays(3), '400.00');

    $this->artisan('members:remind-expiry')->assertSuccessful();
    $this->artisan('members:remind-expiry')->assertSuccessful();

    Queue::assertPushed(SendLineMessage::class, 1);
});
