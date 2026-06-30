/**
 * Phase 4 — Admin Members + Sell flow shared types.
 *
 * These mirror the Inertia props sent by App\Http\Controllers\Admin\
 * {Member,Purchase}Controller. Keep them in sync with the backend contract.
 */

/**
 * Lifecycle status shared by `member_packages.status` AND `entitlements.status`
 * (App\Enums\EntitlementStatus). Serialized as the enum's string value.
 */
export type EntitlementStatus = 'active' | 'expired' | 'used_up';

/** A member row in Admin/Members/Index (controller `through()` projection). */
export type MemberRow = {
    id: number;
    name: string;
    phone: string | null;
    is_active: boolean;
    is_line_linked: boolean;
};

/** A single entitlement (snapshot + live cache) under a lot on the Show page. */
export type EntitlementRow = {
    id: number;
    item_code: string;
    item_name: string;
    item_type: string;
    qty_total: number;
    qty_remaining: number;
    redeem_group: string | null;
    expires_at: string | null;
    status: EntitlementStatus;
};

/** A purchased lot (member_package) with its entitlements, newest first. */
export type MemberPackageRow = {
    id: number;
    package_id: number | null;
    branch_id: number | null;
    purchased_at: string | null;
    expires_at: string | null;
    price_paid: string | number | null;
    status: EntitlementStatus;
    entitlements: EntitlementRow[];
};

/** The full member model rendered on the Show page (with eager-loaded lots). */
export type MemberDetail = {
    id: number;
    name: string;
    phone: string | null;
    email: string | null;
    line_user_id: string | null;
    is_active: boolean;
    member_packages: MemberPackageRow[];
};

/** One row of the aggregate "remaining by type" balance summary. */
export type BalanceLine = {
    item_code: string;
    item_name: string;
    remaining: number;
};

/** An active package the Show page can sell (price = decimal:2 string). */
export type ActivePackageOption = {
    id: number;
    name: string;
    price: string | number;
};

/**
 * Reason a ledger row exists. `redeem` is an operator-driven ตัดสิทธิ์
 * deduction (negative delta); the others come from background lifecycle events.
 * Open-ended on purpose so a new backend reason renders as its raw string.
 */
export type HistoryReason = 'redeem' | 'expire' | 'refund' | (string & {});

/**
 * One row of the redemption / ledger history (Show prop `history`, newest first,
 * capped 50). `delta` is signed — negative for `redeem`/`expire`.
 */
export type HistoryRow = {
    id: number;
    created_at: string | null;
    item_name: string | null;
    reason: HistoryReason;
    delta: number;
    balance_after: number;
    staff_name: string | null;
};

/**
 * One line of the detailed redemption result, flashed under `redemption` after a
 * successful ตัดสิทธิ์. `was_coupled` marks an add-on pulled by a redeem group
 * (e.g. ประคบ taken alongside นวด) — rendered with a "คู่" marker.
 */
export type RedemptionMovement = {
    item_code: string;
    item_name: string | null;
    member_package_id: number;
    expires_at: string | null;
    taken: number;
    remaining_after: number;
    was_coupled: boolean;
};

/** Detailed result of a redeem, flashed under key `redemption` (Inertia flash). */
export type RedemptionResult = {
    item_code: string;
    qty: number;
    movements: RedemptionMovement[];
};
