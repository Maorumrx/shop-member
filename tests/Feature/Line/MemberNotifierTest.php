<?php

declare(strict_types=1);

// Phase — LINE push notifications. MemberNotifier dispatch tests. MemberNotifier is
// the single place that turns a domain event into a queued LINE push; it OWNS the
// copy + the dispatch. Every method is a NO-OP when the member has no line_user_id
// (nobody to push to); otherwise it queues a SendLineMessage. We Queue::fake() so
// nothing runs inline and assert the dispatch (or its absence). Never hits LINE.
// See App\Services\Line\MemberNotifier + App\Jobs\SendLineMessage.

use App\Enums\BookingOrigin;
use App\Enums\BookingStatus;
use App\Jobs\SendLineMessage;
use App\Models\Booking;
use App\Models\Branch;
use App\Models\Member;
use App\Services\Line\MemberNotifier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

const NOTIFIER_SUB = 'U4444444444444444444444444444444';

function notifier(): MemberNotifier
{
    return app(MemberNotifier::class);
}

/**
 * A real member row, LINE-linked or not depending on $lineUserId.
 *
 * @param  array<string, mixed>  $overrides
 */
function notifierMember(?string $lineUserId, array $overrides = []): Member
{
    return Member::create(array_merge([
        'line_user_id' => $lineUserId,
        'name' => 'Notifier Member',
        'phone' => '0870000000',
        'is_active' => true,
    ], $overrides));
}

/** A real confirmed booking for $member (member self-booking origin). */
function notifierBooking(Member $member): Booking
{
    $branch = Branch::create(['name' => 'Notifier Branch', 'is_active' => true]);
    $start = now()->addDay();

    return Booking::create([
        'member_id' => $member->id,
        'branch_id' => $branch->id,
        'item_code' => 'MASSAGE_60',
        'item_name' => 'MASSAGE_60',
        'scheduled_start' => $start,
        'scheduled_end' => $start->copy()->addMinutes(60),
        'slot_length_minutes' => 60,
        'status' => BookingStatus::Confirmed,
        'created_via' => BookingOrigin::Member,
        'created_by_user_id' => null,
        'note' => null,
    ]);
}

it('dispatches NOTHING for a member without a linked LINE id', function () {
    Queue::fake();

    // No line_user_id → each notifier method has nobody to push to.
    $member = notifierMember(null);

    notifier()->welcome($member);
    notifier()->linked($member);
    notifier()->redemptionReceipt($member, 'MASSAGE_60', 1, 4);

    Queue::assertNothingPushed();
});

it('dispatches a SendLineMessage on welcome for a LINE-linked member', function () {
    Queue::fake();

    $member = notifierMember(NOTIFIER_SUB);

    notifier()->welcome($member);

    Queue::assertPushed(SendLineMessage::class, 1);
});

it('dispatches a SendLineMessage on redemptionReceipt for a LINE-linked member', function () {
    Queue::fake();

    $member = notifierMember(NOTIFIER_SUB);

    notifier()->redemptionReceipt($member, 'MASSAGE_60', 2, 8);

    Queue::assertPushed(SendLineMessage::class, 1);
});

it('dispatches a SendLineMessage on bookingConfirmed for a LINE-linked member', function () {
    Queue::fake();

    // bookingConfirmed reads the member off the booking; mint a real booking row
    // for the linked member so the relation resolves.
    $member = notifierMember(NOTIFIER_SUB);
    $booking = notifierBooking($member);

    notifier()->bookingConfirmed($booking);

    Queue::assertPushed(SendLineMessage::class, 1);
});

it('dispatches NOTHING on bookingConfirmed when the member is not LINE-linked', function () {
    Queue::fake();

    $member = notifierMember(null);
    $booking = notifierBooking($member);

    notifier()->bookingConfirmed($booking);

    Queue::assertNothingPushed();
});
