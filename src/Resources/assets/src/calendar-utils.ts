/**
 * Since 2.1.0 (spec 214): the date maths of the calendar. Days are `YYYY-MM-DD`, counted in UTC so a daylight-saving change never
 * shifts one.
 */
export type TaskDates = { start: string; due: string };

export type CalendarEdge = 'start' | 'due';

const DAY_MS = 86_400_000;

function dayNumber(day: string): number {
    const [year, month, date] = day.split('-').map(Number);

    return Date.UTC(year, month - 1, date) / DAY_MS;
}

/** The days from `from` to `to` (negative when `to` is earlier). */
export function dayDiff(from: string, to: string): number {
    return dayNumber(to) - dayNumber(from);
}

export function addDays(day: string, days: number): string {
    return new Date((dayNumber(day) + days) * DAY_MS).toISOString().slice(0, 10);
}

/** FR-027, the body: both dates move by the same days, keeping the duration. A missing date stays missing. */
export function shiftDates(dates: TaskDates, days: number): TaskDates {
    return {
        start: dates.start === '' ? '' : addDays(dates.start, days),
        due: dates.due === '' ? '' : addDays(dates.due, days),
    };
}

/** FR-027, an edge: that date goes to `day`, and stops at the other date rather than passing it. */
export function dragEdge(dates: TaskDates, edge: CalendarEdge, day: string): TaskDates {
    if (edge === 'start') {
        return { start: dates.due !== '' && day > dates.due ? dates.due : day, due: dates.due };
    }

    return { start: dates.start, due: dates.start !== '' && day < dates.start ? dates.start : day };
}

export function sameDates(a: TaskDates, b: TaskDates): boolean {
    return a.start === b.start && a.due === b.due;
}

/** The day a grabbed bar is held by: the cell under the pointer, counted from the bar's first cell. */
export function grabOffset(pointerX: number, left: number, width: number, span: number): number {
    if (span <= 1 || width <= 0) {
        return 0;
    }

    return Math.min(span - 1, Math.max(0, Math.floor(((pointerX - left) / width) * span)));
}

/** FR-031: the cell an arrow key goes to in a grid of seven columns; null when the key does not move, or leaves the grid. */
export function gridStep(key: string, index: number, count: number): number | null {
    const step: Record<string, number> = { ArrowLeft: -1, ArrowRight: 1, ArrowUp: -7, ArrowDown: 7 };
    let next: number | null = null;
    if (step[key] !== undefined) {
        next = index + step[key];
    } else if (key === 'Home') {
        next = index - (index % 7);
    } else if (key === 'End') {
        next = index - (index % 7) + 6;
    }

    return next !== null && next >= 0 && next < count ? next : null;
}

/** FR-025: on a phone a calendar opened with no mode goes to the list. */
export function phoneListUrl(href: string, phone: boolean): string | null {
    const url = new URL(href);
    if (!phone || url.searchParams.has('mode')) {
        return null;
    }
    url.searchParams.set('mode', 'list');

    return url.toString();
}

/**
 * Since 2.14.0 (`calendar.skip_non_working_days`): the day itself when it is a working day, else the next one, at most `limit`
 * days later (a run of non-working days longer than that keeps the day: the server still answers for the dates).
 */
export function snapToWorkingDay(day: string, nonWorking: ReadonlySet<string>, limit = 31): string {
    let next = day;
    for (let step = 0; step < limit && nonWorking.has(next); step++) {
        next = addDays(next, 1);
    }

    return nonWorking.has(next) ? day : next;
}

/**
 * Since 2.14.0: a moved bar lands on working days. The first date goes to the next working day and the other follows by the same
 * days (the duration stays), then it too goes to the next working day. A missing date stays missing.
 */
export function snapDates(dates: TaskDates, nonWorking: ReadonlySet<string>): TaskDates {
    const first = dates.start !== '' ? dates.start : dates.due;
    if (first === '' || nonWorking.size === 0) {
        return dates;
    }
    const shifted = shiftDates(dates, dayDiff(first, snapToWorkingDay(first, nonWorking)));

    return {
        start: shifted.start,
        due: shifted.due === '' || dates.start === '' ? shifted.due : snapToWorkingDay(shifted.due, nonWorking),
    };
}

/**
 * Since 2.14.1 (audit NN-L2): the dates a dropped bar is written with, or null when the move snaps back onto the dates it
 * already has (a drop on a non-working day that snaps to where it was): nothing to write, nothing to undo.
 */
export function movedDates(dates: TaskDates, days: number, nonWorking: ReadonlySet<string>): TaskDates | null {
    const next = snapDates(shiftDates(dates, days), nonWorking);

    return sameDates(next, dates) ? null : next;
}
