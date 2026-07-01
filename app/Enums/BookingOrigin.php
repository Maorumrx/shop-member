<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Origin (which guard created it) for `bookings.created_via`
 * (docs/phase7-booking-design.md §3.2). String-backed, matching the existing
 * enum style; captures the dual-guard model.
 *
 * - member: LIFF self-booking on the `members` guard. `created_by_user_id` is
 *   NULL (a member is not a `users` row).
 * - staff:  counter/admin booking on the `users` guard. `created_by_user_id`
 *   is the acting staff/owner.
 *
 * A DB CHECK keeps this consistent with `created_by_user_id` (§3.2): staff ⇒ id
 * present, member ⇒ id null.
 */
enum BookingOrigin: string
{
    case Member = 'member';
    case Staff = 'staff';
}
