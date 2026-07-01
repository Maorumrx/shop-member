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

/* ── Phase 6 — Member-facing dashboard (Member/Dashboard) ────────────────── */

/**
 * Item classification shared with App\Enums\ItemType. `service` = a main
 * redeemable item; `addon` = an extra (rendered with a "เสริม" badge).
 */
export type ItemType = 'service' | 'addon';

/** The authenticated member's greeting profile (Dashboard prop `member`). */
export type MemberProfile = {
    name: string;
    avatar_url: string | null;
};

/**
 * One item line inside an active lot on the dashboard. `qty_remaining` can be 0
 * (the member sees the full package); `item_type` drives the add-on badge.
 */
export type MemberLotItem = {
    item_name: string;
    item_type: ItemType;
    qty_remaining: number;
    qty_total: number;
};

/**
 * One ACTIVE lot on the member dashboard (Dashboard prop `lots`, near-expiry
 * first). `package_name` is null after catalog cleanup (SET NULL, §5.1);
 * `expires_at` null = never expires. `is_near_expiry` is server-computed.
 */
export type MemberLot = {
    id: number;
    package_name: string | null;
    purchased_at: string | null;
    expires_at: string | null;
    is_near_expiry: boolean;
    items: MemberLotItem[];
};

/**
 * One row of the member-facing history feed (Dashboard prop `history`). Same
 * shape as the admin HistoryRow MINUS `staff_name` — the member view never
 * receives who performed the movement.
 */
export type MemberHistoryRow = {
    id: number;
    created_at: string | null;
    item_name: string | null;
    reason: HistoryReason;
    delta: number;
    balance_after: number;
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

/* ── Phase 8 — Member ↔ LINE account linking ─────────────────────────────── */

/**
 * The one-off claim code flashed back to Admin/Members/Show under `linkCode`
 * after a successful `POST /members/{member}/link-code`
 * (docs/member-line-linking-design.md §4.2). `code` is the plaintext 6-digit
 * value shown ONCE to staff (never persisted); `expires_at` is an ISO-8601
 * string the page renders via `formatThaiDateTime`.
 */
export type LinkCode = {
    code: string;
    expires_at: string;
};

/**
 * JSON returned by `POST /member/line/login` (NOT an Inertia response — the LIFF
 * page reads it via axios, §4.1):
 *  - `{ ok: true }` → a matching member exists and is now logged in → dashboard.
 *  - `{ ok: false, state: 'needs_link' }` → first-time LINE user, verified but
 *    unlinked; the page shows the link-or-create choice screen. The verified LINE
 *    identity is parked server-side in the `pending_line` session.
 *  - a 422 body `{ ok: false, message }` (LINE verify failed) is surfaced by the
 *    existing axios error path, not this success union.
 */
export type LineLoginResponse =
    { ok: true } | { ok: false; state: 'needs_link' };

/**
 * JSON returned by the pending-state follow-ups `POST /member/line/submit-code`
 * and `POST /member/line/create-new` (§4.2). On `ok` the member is logged in →
 * redirect to the dashboard. A 422 body `{ ok: false, message }` (invalid /
 * expired / burned code, or an expired pending session) is shown to the customer.
 */
export type LineLinkResponse = { ok: true } | { ok: false; message: string };
