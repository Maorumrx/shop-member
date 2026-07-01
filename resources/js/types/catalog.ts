/**
 * Phase 3 — Admin Package Catalog shared types.
 *
 * These mirror the Inertia props sent by App\Http\Controllers\Admin\
 * {Branch,Package}Controller. Keep them in sync with the backend contract.
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

/** A minimal active-branch option used by the package picker/filter. */
export type BranchOption = {
    id: number;
    name: string;
};

export type PackageLineType = 'service' | 'addon';

/** A package row in Admin/Packages/Index. */
export type PackageRow = {
    id: number;
    name: string;
    price: number | string;
    valid_days: number | null;
    branch: BranchOption | null;
    is_active: boolean;
    lines_count: number;
};

/** A single line as returned on the Edit page. */
export type PackageLine = {
    id?: number;
    item_code: string;
    item_name: string;
    item_type: PackageLineType;
    qty: number;
    redeem_group: string | null;
};

/** The full package payload for the Edit page. */
export type PackageDetail = {
    id: number;
    name: string;
    price: number | string;
    valid_days: number | null;
    branch_id: number | null;
    is_active: boolean;
    lines: PackageLine[];
};
