<?php

declare(strict_types=1);

// Phase — LINE push notifications: RESILIENCE. The key contract is that a failing
// LINE push must NEVER break the action that triggered it. Here a LINE-linked
// member creates a booking (member.bookings.store) while the LINE push endpoint is
// faked to FAIL (403/500). The booking must still succeed — row created, normal
// redirect — because the confirmation push is QUEUED (SendLineMessage) after the
// booking commits, so it never runs inline and its outcome can't affect the request.
// Queue::fake() proves the push doesn't run inline; the stacked Http::fake makes the
// "a push WOULD fail" intent explicit. See App\Http\Controllers\Member\BookingController.

use App\Enums\BookingStatus;
use App\Models\Booking;
use App\Models\Branch;
use App\Models\BranchBookingSetting;
use App\Models\Member;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

const FLOW_PUSH_URL = 'https://api.line.me/v2/bot/message/push';

/** Fixed local "now" — Mon 2026-08-03 10:00, mid-window, on the 60-min grid. */
function flowNow(): CarbonImmutable
{
    return CarbonImmutable::parse('2026-08-03 10:00:00');
}

/** A grid-aligned FUTURE slot relative to flowNow() (default 14:00 tomorrow). */
function flowSlot(int $daysAhead = 1, int $hour = 14): CarbonImmutable
{
    return flowNow()->startOfDay()->addDays($daysAhead)->setTime($hour, 0, 0);
}

function flowBookableBranch(): Branch
{
    $branch = Branch::create(['name' => 'Flow Branch', 'is_active' => true]);

    BranchBookingSetting::create([
        'branch_id' => $branch->id,
        'is_bookable' => true,
        'slot_capacity' => 2,
        'slot_length_minutes' => 60,
        'open_time' => '09:00:00',
        'close_time' => '20:00:00',
        'max_advance_days' => 30,
    ]);

    return $branch;
}

/** A LINE-linked, active member on the `members` guard. */
function flowLinkedMember(): Member
{
    return Member::create([
        'line_user_id' => 'U7000000000000000000000000000001',
        'name' => 'Flow Booker',
        'phone' => '0890000000',
        'is_active' => true,
    ]);
}

beforeEach(function () {
    $this->travelTo(flowNow());
});

it('creates the booking even when the LINE push endpoint would fail (403)', function () {
    // Queue::fake() → the confirmation push (SendLineMessage) is enqueued, never run
    // inline, so the request can't wait on or fail because of LINE.
    Queue::fake();
    // And even if it DID run, LINE is rejecting — but the service is fail-safe and
    // this stacked fake documents the "a push WOULD fail" premise.
    Http::fake([
        FLOW_PUSH_URL => Http::response(['message' => 'not a friend'], 403),
    ]);

    $branch = flowBookableBranch();
    $member = flowLinkedMember();

    $this->actingAs($member, 'members')
        ->post(route('member.bookings.store'), [
            'branch_id' => $branch->id,
            'item_code' => 'MASSAGE_60',
            'scheduled_start' => flowSlot()->toIso8601String(),
        ])
        ->assertRedirect(route('member.bookings.index'));

    // The booking committed for this member — the push failure never touched it.
    $booking = Booking::sole();
    expect($booking->member_id)->toBe($member->id);
    expect($booking->status)->toBe(BookingStatus::Confirmed);

    // The push was queued (deferred), never sent inline during the web request.
    Http::assertNothingSent();
});

it('creates the booking even when the LINE push endpoint would 500', function () {
    Queue::fake();
    Http::fake([
        FLOW_PUSH_URL => Http::response(['message' => 'server error'], 500),
    ]);

    $branch = flowBookableBranch();
    $member = flowLinkedMember();

    $this->actingAs($member, 'members')
        ->post(route('member.bookings.store'), [
            'branch_id' => $branch->id,
            'item_code' => 'MASSAGE_60',
            'scheduled_start' => flowSlot()->toIso8601String(),
        ])
        ->assertRedirect(route('member.bookings.index'));

    expect(Booking::sole()->status)->toBe(BookingStatus::Confirmed);
    Http::assertNothingSent();
});
