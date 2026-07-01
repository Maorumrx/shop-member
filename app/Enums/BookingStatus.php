<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Lifecycle vocabulary for `bookings.status` (docs/phase7-booking-design.md §4).
 * String-backed, same style as {@see EntitlementStatus}/{@see LedgerReason}.
 *
 * v1 uses AUTO-CONFIRM: a new booking is created directly as `confirmed`
 * (holding the slot immediately). The design's `pending` status is DROPPED from
 * v1 per the client decision, so the state machine is:
 *
 *     confirmed ──check_in──► completed   (terminal success; redemption ran)
 *        │
 *        ├──────cancel───────► cancelled  (terminal; frees the slot)
 *        │
 *        └────scheduled_end<now (sweep) / staff ──► no_show  (terminal)
 *
 * `checked_in` is a transient state emitted DURING the single check-in
 * transaction; in v1 the row settles on `completed` the instant redemption
 * succeeds (§7). It remains in the enum for the intra-transaction transition and
 * so a future "in service" board can linger on it.
 *
 * CAPACITY: a slot is occupied by rows in `confirmed` or `checked_in`. The
 * terminal states (`completed`, `cancelled`, `no_show`) never hold capacity.
 *
 * - confirmed:  active reservation; the slot capacity is HELD by this row.
 * - checked_in: member arrived; redemption runs at this transition (transient).
 * - completed:  service done + entitlement consumed (ledger.booking_id set). Terminal.
 * - cancelled:  called off by member/staff (frees the slot). Terminal.
 * - no_show:    confirmed but the slot elapsed with no check-in. Terminal.
 */
enum BookingStatus: string
{
    case Confirmed = 'confirmed';
    case CheckedIn = 'checked_in';
    case Completed = 'completed';
    case Cancelled = 'cancelled';
    case NoShow = 'no_show';
}
