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
 * Thai labels for the member-facing ledger reasons. Centralized so translations
 * don't drift across surfaces. Open-ended callers should fall back to the raw
 * reason string for any backend-added reason.
 */
export const REASON_LABELS: Record<'redeem' | 'expire' | 'refund', string> = {
    redeem: 'ใช้บริการ',
    expire: 'หมดอายุ',
    refund: 'คืนสิทธิ์',
};

/** Reason label with a raw-string fallback for any unmapped backend reason. */
export function reasonLabel(reason: HistoryReason): string {
    return REASON_LABELS[reason as keyof typeof REASON_LABELS] ?? reason;
}
