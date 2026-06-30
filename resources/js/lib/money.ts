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
