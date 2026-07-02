/**
 * Phase 7 — Booking (จองคิว) shared types.
 *
 * These mirror the Inertia props sent by the Member + Admin booking controllers
 * (member `Member\BookingController`; admin `Admin\BookingController`). Keep them
 * in sync with the backend contract documented in the Phase 7 prompt.
 */

/**
 * A booking's lifecycle status (App\Enums\BookingStatus). Serialized as the
 * enum's string value. `confirmed` is the only actionable (cancellable /
 * check-in-able) state; the rest are terminal.
 */
export type BookingStatus =
    'confirmed' | 'checked_in' | 'completed' | 'cancelled' | 'no_show';

/** How a booking was created — member self-service vs staff at the counter. */
export type BookingCreatedVia = 'member' | 'staff';

/**
 * A bookable branch's scheduling config. `slot_length_minutes` sizes each slot;
 * `open_time`/`close_time` are 'HH:MM' strings; `max_advance_days` caps how far
 * ahead a member may book (the day picker's upper bound). `slot_capacity` is
 * admin-only (the member never sees raw capacity, only per-slot `remaining`).
 */
export type BookingBranch = {
    id: number;
    name: string;
    slot_length_minutes: number;
    open_time: string;
    close_time: string;
    max_advance_days: number;
    /** Admin board only — per-slot seat count. */
    slot_capacity?: number;
};

/**
 * A bookable service option (from the branch's catalog). Carries the baht `price`
 * (decimal-2 STRING) debited from the member's wallet at check-in; shown in the
 * picker so staff/members see the cost up front.
 */
export type BookingService = {
    item_code: string;
    item_name: string;
    price: string | number;
};

/**
 * One availability slot returned by the availability endpoint (member JSON) OR
 * embedded in the admin index props. `remaining` is seats left; `is_full` is the
 * server's convenience flag (remaining <= 0). Past slots for today are omitted
 * server-side, so every slot here is bookable time-wise.
 */
export type BookingSlot = {
    start: string;
    end: string;
    remaining: number;
    is_full: boolean;
};

/** The member availability endpoint's JSON envelope. */
export type AvailabilityResponse = {
    slots: BookingSlot[];
};

/**
 * One row in the MEMBER's own booking lists (`upcoming` / `recent`). No staff
 * names, no member id — the member only ever sees their own bookings.
 */
export type MemberBookingRow = {
    id: number;
    branch_name: string | null;
    item_code: string;
    item_name: string;
    scheduled_start: string | null;
    scheduled_end: string | null;
    status: BookingStatus;
    note: string | null;
};

/**
 * One row in the ADMIN day board (`bookings`). Carries the member + audit trail
 * (who created it, the terminal timestamps) the member view never receives.
 */
export type AdminBookingRow = {
    id: number;
    member_id: number;
    member_name: string | null;
    item_code: string;
    item_name: string;
    scheduled_start: string | null;
    scheduled_end: string | null;
    status: BookingStatus;
    created_via: BookingCreatedVia;
    created_by_name: string | null;
    checked_in_at: string | null;
    completed_at: string | null;
    cancelled_at: string | null;
    note: string | null;
};

/** The admin day board's active branch + date filter. */
export type BookingFilters = {
    branch_id: number | null;
    date: string;
};

/**
 * Thai labels for every booking status. Centralized so the member pill, the
 * admin badge, and any future surface can't drift apart in wording.
 */
export const BOOKING_STATUS_LABELS: Record<BookingStatus, string> = {
    confirmed: 'ยืนยันแล้ว',
    checked_in: 'เช็คอินแล้ว',
    completed: 'ใช้บริการแล้ว',
    cancelled: 'ยกเลิก',
    no_show: 'ไม่มาตามนัด',
};

/** Status label with a raw-string fallback for any unmapped backend status. */
export function bookingStatusLabel(status: BookingStatus): string {
    return BOOKING_STATUS_LABELS[status] ?? status;
}

/** Statuses a member/staff may still cancel (the only non-terminal state). */
export function isCancellable(status: BookingStatus): boolean {
    return status === 'confirmed';
}
