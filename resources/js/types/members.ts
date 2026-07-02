/**
 * Phase 4 + credit-wallet reframe — Admin Members + wallet flow shared types.
 *
 * These mirror the Inertia props sent by App\Http\Controllers\Admin\
 * {Member,Topup,MemberWallet}Controller and the member DashboardController. The
 * package/entitlement world is gone: a member now holds ONE spendable money
 * wallet (a balance + credit lots), and every movement is a ledger row.
 *
 * ALL money is a decimal-2 STRING (§5.6) — never cast to int/float; render with
 * `formatBaht` / `formatSignedBaht`.
 */

/** A member row in Admin/Members/Index (controller `through()` projection). */
export type MemberRow = {
    id: number;
    name: string;
    phone: string | null;
    is_active: boolean;
    is_line_linked: boolean;
};

/** The member model rendered on the Show page (wallet props are separate). */
export type MemberDetail = {
    id: number;
    name: string;
    phone: string | null;
    email: string | null;
    line_user_id: string | null;
    is_active: boolean;
};

/* ── Credit lots ─────────────────────────────────────────────────────────── */

/**
 * How a credit lot was created (App\Enums\CreditSource). `topup` = a sale;
 * `adjustment` = an owner grant. Open-ended so a new backend source renders raw.
 */
export type CreditSource = 'topup' | 'adjustment' | (string & {});

/**
 * One ACTIVE credit lot (App\Services\Member\MemberWalletQuery::activeLots), near-
 * expiry first. `amount_paid`/`bonus_amount` are the lot's ORIGINAL amounts;
 * `*_remaining` are what's left; `total_remaining` = paid + bonus remaining.
 * `expires_at` null = never expires; `is_near_expiry` is server-computed. All
 * money fields are decimal-2 STRINGS.
 */
export type WalletLot = {
    id: number;
    source: CreditSource;
    amount_paid: string;
    bonus_amount: string;
    paid_remaining: string;
    bonus_remaining: string;
    total_remaining: string;
    purchased_at: string | null;
    expires_at: string | null;
    is_near_expiry: boolean;
};

/* ── Wallet history (ledger) ─────────────────────────────────────────────── */

/**
 * Reason a ledger row exists (App\Enums\CreditLedgerReason). Positive delta:
 * `topup`/`bonus` and a positive `adjust`; negative: `debit`/`refund`/`expire`
 * and a negative `adjust`. Open-ended so a new backend reason renders raw.
 */
export type HistoryReason =
    | 'topup'
    | 'bonus'
    | 'debit'
    | 'refund'
    | 'expire'
    | 'adjust'
    | (string & {});

/**
 * One row of the wallet history (Show prop `history`, newest first, capped 50).
 * `delta` and `balance_after` are SIGNED decimal-2 STRINGS. The admin view keeps
 * `staff_name` (who performed it); the member view omits it.
 */
export type WalletHistoryRow = {
    id: number;
    created_at: string | null;
    reason: HistoryReason;
    delta: string;
    balance_after: string;
    note: string | null;
    credit_lot_id: number | null;
    booking_id: number | null;
    staff_name: string | null;
};

/** The member-facing history row — same as WalletHistoryRow MINUS `staff_name`. */
export type MemberWalletHistoryRow = Omit<WalletHistoryRow, 'staff_name'>;

/* ── Sell/charge inputs (Show page) ──────────────────────────────────────── */

/** A top-up preset for the sell form (id + amount/bonus, decimal-2 strings). */
export type TopupOfferOption = {
    id: number;
    name: string;
    amount: string | number;
    bonus: string | number;
};

/** A priced service for the manual-charge picker (item_code + name + price). */
export type ServiceOption = {
    item_code: string;
    name: string;
    price: string | number;
};

/* ── Wallet action result (flashed under `walletResult`) ─────────────────── */

/**
 * One touched-lot movement inside a WalletResult (WalletMovement::toArray).
 * Money fields are SIGNED decimal-2 STRINGS.
 */
export type WalletMovement = {
    credit_lot_id: number;
    reason: HistoryReason;
    delta: string;
    paid_delta: string;
    bonus_delta: string;
    lot_remaining_after: string;
    lot_status: string;
    balance_after: string;
};

/**
 * The detailed outcome of a charge/refund/adjust, flashed under `walletResult`
 * (WalletTransactionResult::toArray). `net_delta` is the SIGNED wallet change;
 * `balance_after` the resulting spendable balance. Purely for a transient banner —
 * the redirect already refreshes the balance/lots/history.
 */
export type WalletResult = {
    reason: HistoryReason;
    net_delta: string;
    balance_after: string;
    credit_lot_id: number | null;
    movements: WalletMovement[];
};

/* ── Member-facing dashboard (Member/Dashboard) ──────────────────────────── */

/** The authenticated member's greeting profile (Dashboard prop `member`). */
export type MemberProfile = {
    name: string;
    avatar_url: string | null;
};

/* ── Member ↔ LINE account linking ───────────────────────────────────────── */

/**
 * The one-off claim code flashed back to Admin/Members/Show under `linkCode`
 * after a successful `POST /members/{member}/link-code`. `code` is shown ONCE
 * (never persisted); `expires_at` is an ISO-8601 string.
 */
export type LinkCode = {
    code: string;
    expires_at: string;
};

/**
 * JSON returned by `POST /member/line/login` (read via axios on the LIFF page):
 *  - `{ ok: true }` → matching member exists and is now logged in → dashboard.
 *  - `{ ok: false, state: 'needs_link' }` → first-time LINE user, verified but
 *    unlinked; the page shows the link-or-create choice screen.
 */
export type LineLoginResponse =
    | { ok: true }
    | { ok: false; state: 'needs_link' };

/**
 * JSON returned by the pending-state follow-ups `POST /member/line/submit-code`
 * and `POST /member/line/create-new`. On `ok` the member is logged in → redirect
 * to the dashboard; a 422 body `{ ok: false, message }` is shown to the customer.
 */
export type LineLinkResponse = { ok: true } | { ok: false; message: string };
