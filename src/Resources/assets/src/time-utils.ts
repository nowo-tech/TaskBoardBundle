/**
 * Since 1.15: durations and the estimate / spent totals of the list view (the same rules as the PHP TaskDeviation).
 */
export type TimeRow = { estimate: number | null; spent: number };

export type TimeTotals = {
    estimate: number | null;
    spent: number;
    difference: number | null;
    percent: number | null;
    status: 'none' | 'on_track' | 'over';
};

/** Minutes as "45 min", "2 h", "1 h 30 min"; signed adds "+" / "−"; null gives "—". */
export function formatMinutes(minutes: number | null, signed = false): string {
    if (minutes === null) {
        return '—';
    }
    const sign = signed && minutes > 0 ? '+' : minutes < 0 ? '−' : '';
    const total = Math.abs(minutes);
    const hours = Math.floor(total / 60);
    const rest = total % 60;
    const text = hours === 0 ? `${rest} min` : rest === 0 ? `${hours} h` : `${hours} h ${rest} min`;

    return sign + text;
}

/** Reads a row's data-estimate ("" = none) and data-spent. */
export function readTimeRow(dataset: DOMStringMap): TimeRow {
    const estimate = Number.parseInt(dataset.estimate ?? '', 10);
    const spent = Number.parseInt(dataset.spent ?? '', 10);

    return { estimate: Number.isNaN(estimate) ? null : estimate, spent: Number.isNaN(spent) ? 0 : spent };
}

/**
 * Sums of the rows: every estimate, every spent time; the difference compares the estimate with the time spent on
 * the estimated rows only (a task without an estimate has no deviation).
 */
export function sumTimes(rows: TimeRow[]): TimeTotals {
    let estimate: number | null = null;
    let spent = 0;
    let spentOnEstimated = 0;
    for (const row of rows) {
        spent += row.spent;
        if (row.estimate !== null) {
            estimate = (estimate ?? 0) + row.estimate;
            spentOnEstimated += row.spent;
        }
    }
    if (estimate === null || estimate <= 0) {
        return { estimate, spent, difference: null, percent: null, status: 'none' };
    }
    const difference = spentOnEstimated - estimate;

    return {
        estimate,
        spent,
        difference,
        percent: Math.round((difference * 100) / estimate),
        status: difference > 0 ? 'over' : 'on_track',
    };
}

/** "+1 h (+25 %)" or "—". */
export function formatDeviation(totals: TimeTotals): string {
    if (totals.difference === null || totals.percent === null) {
        return '—';
    }

    return `${formatMinutes(totals.difference, true)} (${totals.percent > 0 ? '+' : ''}${totals.percent} %)`;
}
