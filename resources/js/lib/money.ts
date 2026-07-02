/**
 * Thai Baht money formatting for the admin catalog UI.
 *
 * Prices arrive from Laravel as a `decimal` cast — i.e. a string like "1500.00"
 * (or a number). Normalize to a number, then render with the Thai locale and a
 * leading ฿ symbol, e.g. `฿1,500.00`.
 */
const bahtFormatter = new Intl.NumberFormat('th-TH', {
    minimumFractionDigits: 2,
    maximumFractionDigits: 2,
});

export function formatBaht(value: number | string | null | undefined): string {
    const amount =
        typeof value === 'number' ? value : Number.parseFloat(value ?? '0');

    if (Number.isNaN(amount)) {
        return '฿0.00';
    }

    return `฿${bahtFormatter.format(amount)}`;
}

/**
 * A SIGNED baht amount for a ledger delta, e.g. `+฿1,000.00` / `-฿300.00` (zero
 * renders bare `฿0.00`). The sign leads the ฿ symbol and the magnitude is always
 * shown positive — never `฿-300.00`.
 */
export function formatSignedBaht(
    value: number | string | null | undefined,
): string {
    const amount =
        typeof value === 'number' ? value : Number.parseFloat(value ?? '0');

    if (Number.isNaN(amount)) {
        return '฿0.00';
    }

    const sign = amount > 0 ? '+' : amount < 0 ? '-' : '';

    return `${sign}${formatBaht(Math.abs(amount))}`;
}
