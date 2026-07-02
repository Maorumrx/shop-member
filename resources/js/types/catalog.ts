/**
 * Phase 3 (credit-wallet reframe) — Admin catalog shared types.
 *
 * These mirror the Inertia props sent by App\Http\Controllers\Admin\
 * {Branch,Service,TopupOffer}Controller. The old Package catalog is gone; the
 * money wallet replaces it with two catalogs: `services` (the baht price list the
 * debit path consumes) and `topup_offers` (sell-screen presets). Money is a
 * decimal-2 STRING throughout (§5.6) — render it with `formatBaht`.
 */

/** A Laravel length-aware paginator, narrowed to what the admin tables use. */
export type Paginator<T> = {
    data: T[];
    current_page: number;
    last_page: number;
    per_page: number;
    total: number;
    from: number | null;
    to: number | null;
    links: PaginatorLink[];
    first_page_url: string | null;
    last_page_url: string | null;
    next_page_url: string | null;
    prev_page_url: string | null;
    path: string;
};

export type PaginatorLink = {
    url: string | null;
    label: string;
    active: boolean;
};

/**
 * A branch's per-slot booking config (Phase 7). Times are 'H:i' for the UI (the
 * column stores 'H:i:s'). `null` on a BranchRow means the branch has no settings
 * row yet — the editor pre-fills sensible defaults in that case.
 */
export type BranchBooking = {
    is_bookable: boolean;
    slot_capacity: number;
    slot_length_minutes: number;
    open_time: string;
    close_time: string;
    max_advance_days: number;
};

/** A branch row in Admin/Branches/Index. */
export type BranchRow = {
    id: number;
    name: string;
    is_active: boolean;
    booking: BranchBooking | null;
};

/** A minimal active-branch option used by the service picker/filter. */
export type BranchOption = {
    id: number;
    name: string;
};

/* ── Services (the baht price list) ──────────────────────────────────────── */

/**
 * A service row in Admin/Services/Index (the paginated price list). `price` is a
 * decimal-2 string (or number); `branch` is the eager-loaded scope (null =
 * any-branch price). `item_code` is globally unique.
 */
export type ServiceRow = {
    id: number;
    item_code: string;
    name: string;
    price: string | number;
    is_active: boolean;
    branch: BranchOption | null;
};

/** The full service payload for the Edit page (the raw model). */
export type ServiceDetail = {
    id: number;
    item_code: string;
    name: string;
    price: string | number;
    branch_id: number | null;
    is_active: boolean;
};

/* ── Top-up offers (sell-screen presets) ─────────────────────────────────── */

/**
 * A top-up preset row in Admin/TopupOffers/Index. `amount` is the cash the
 * customer pays; `bonus` is the promotional add-on (spendable = amount + bonus).
 * Both are decimal-2 strings (or numbers). Managed inline (no dedicated pages).
 */
export type TopupOfferRow = {
    id: number;
    name: string;
    amount: string | number;
    bonus: string | number;
    is_active: boolean;
    sort_order: number;
};
