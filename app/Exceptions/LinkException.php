<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

/**
 * Raised by {@see \App\Services\Line\MemberLinkService} when a member ↔ LINE
 * claim-code operation cannot be honoured (docs/member-line-linking-design.md
 * §3, §5). A DOMAIN exception, distinct from a validation error: the request
 * shape has already passed the FormRequest (a well-formed 6-digit code, a
 * present pending-LINE session), but a security/lifecycle rule fails.
 *
 * The two entry points map onto it:
 *   - generate() rejects minting a code for a member that is already LINE-linked,
 *     inactive, or soft-deleted (nothing to claim / must never be claimable).
 *   - claim() FAILS CLOSED: an unknown/expired/consumed code, an over-attempted
 *     (burned) code, or a target member that became linked/inactive/trashed
 *     between mint and redemption all raise this — and the whole claim
 *     transaction rolls back (no line_user_id attached, no code consumed).
 *
 * SECURITY NOTE — message opacity: the claim() messages are deliberately generic
 * ("This code is not valid." / "This account can't be linked.") so the caller
 * can surface them to an UNAUTHENTICATED customer WITHOUT leaking which member a
 * code points at, whether a member exists, or why exactly a code failed. The
 * controller returns these verbatim; do not enrich them with member identifiers.
 *
 * @see \App\Services\Line\MemberLinkService
 */
final class LinkException extends RuntimeException
{
    /**
     * generate(): the target member already has a LINE account attached
     * (`line_user_id` is non-null), so there is nothing to link. Admin-side — the
     * "Generate code" button is hidden for linked members; this is the race/defence
     * backstop. Safe to show staff (it is not an unauthenticated-customer path).
     */
    public static function alreadyLinked(): self
    {
        return new self('This member is already linked to a LINE account.');
    }

    /**
     * generate(): the target member is inactive (`is_active = false`) or
     * soft-deleted. A code must never be minted for a disabled/removed member
     * (§3 "Inactive / soft-deleted member can't be claimed"). Admin-side message.
     */
    public static function memberUnavailable(): self
    {
        return new self('A code cannot be generated for a disabled or removed member.');
    }

    /**
     * claim(): no LIVE code matches the submitted digits — unknown, expired,
     * already consumed, or superseded by a regenerate. Also the message used when
     * a matching-hash row exists but is dead (so a probing attacker can't tell
     * "wrong code" from "expired code"). Generic ON PURPOSE (§3, security note).
     */
    public static function invalidCode(): self
    {
        return new self('This code is not valid. Please ask the shop for a new one.');
    }

    /**
     * claim(): a live code was found, but the target member is no longer eligible
     * — it became LINE-linked, inactive, or soft-deleted between mint and
     * redemption (§5 "Code entered for a member that meanwhile got linked /
     * disabled"). Fail closed. Generic message — never reveals the member.
     */
    public static function memberNotClaimable(): self
    {
        return new self('This code is no longer valid. Please ask the shop for a new one.');
    }

    /**
     * claim(): the per-code brute-force cap (5 wrong entries) was reached; the
     * code has been burned (consumed) inside the same transaction. The customer
     * must ask staff to regenerate (§3 per-code brute-force guard). Generic.
     */
    public static function tooManyAttempts(): self
    {
        return new self('This code is no longer valid. Please ask the shop for a new one.');
    }
}
