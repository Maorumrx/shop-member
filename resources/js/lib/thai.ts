/**
 * Thai date + ledger-reason helpers for the member-facing UI (Phase 6).
 *
 * Dates arrive from Laravel as ISO-8601 strings (or null). The member dashboard
 * renders them in the Thai Buddhist era, short form (e.g. "3 ก.ค. 2569").
 * Reason labels are centralized so the member feed and any future surface can't
 * drift apart in wording.
 */
import type { HistoryReason } from '@/types/members';

const thaiDateFormatter = new Intl.DateTimeFormat('th-TH-u-ca-buddhist', {
    year: 'numeric',
    month: 'short',
    day: 'numeric',
});

const thaiDateTimeFormatter = new Intl.DateTimeFormat('th-TH-u-ca-buddhist', {
    day: 'numeric',
    month: 'short',
    hour: '2-digit',
    minute: '2-digit',
});

const thaiTimeFormatter = new Intl.DateTimeFormat('th-TH', {
    hour: '2-digit',
    minute: '2-digit',
});

/**
 * Short Thai Buddhist-era date, e.g. "3 ก.ค. 2569". `null`/unparseable → '—'.
 */
export function formatThaiDate(iso: string | null): string {
    if (!iso) {
        return '—';
    }

    const date = new Date(iso);

    if (Number.isNaN(date.getTime())) {
        return '—';
    }

    return thaiDateFormatter.format(date);
}

/**
 * Short Thai Buddhist-era date + time for a history row (a ledger entry is a
 * point in time), e.g. "3 ก.ค. 14:05". `null`/unparseable → '—'.
 */
export function formatThaiDateTime(iso: string | null): string {
    if (!iso) {
        return '—';
    }

    const date = new Date(iso);

    if (Number.isNaN(date.getTime())) {
        return '—';
    }

    return thaiDateTimeFormatter.format(date);
}

/**
 * Time-of-day only, e.g. "14:05" — for a booking slot / scheduled time where the
 * date is shown separately. `null`/unparseable → '—'.
 */
export function formatThaiTime(iso: string | null): string {
    if (!iso) {
        return '—';
    }

    const date = new Date(iso);

    if (Number.isNaN(date.getTime())) {
        return '—';
    }

    return thaiTimeFormatter.format(date);
}

/**
 * A slot's time range, e.g. "14:00 – 14:30". Either end `null`/unparseable
 * collapses to the single available side (or '—' if both are missing).
 */
export function formatThaiTimeRange(
    startIso: string | null,
    endIso: string | null,
): string {
    const start = formatThaiTime(startIso);
    const end = formatThaiTime(endIso);

    if (start === '—') {
        return end;
    }

    if (end === '—') {
        return start;
    }

    return `${start} – ${end}`;
}

/**
 * Thai labels for the wallet ledger reasons (App\Enums\CreditLedgerReason).
 * Centralized so the member feed and the admin history can't drift apart in
 * wording. Open-ended callers fall back to the raw reason string for any
 * backend-added reason.
 */
export const REASON_LABELS: Record<
    'topup' | 'bonus' | 'debit' | 'refund' | 'expire' | 'adjust',
    string
> = {
    topup: 'เติมเงิน',
    bonus: 'โบนัส',
    debit: 'ใช้บริการ',
    refund: 'คืนเงิน',
    expire: 'หมดอายุ',
    adjust: 'ปรับยอด',
};

/** Reason label with a raw-string fallback for any unmapped backend reason. */
export function reasonLabel(reason: HistoryReason): string {
    return REASON_LABELS[reason as keyof typeof REASON_LABELS] ?? reason;
}
