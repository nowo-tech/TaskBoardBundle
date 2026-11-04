/**
 * Since 1.23: pure helpers of the provider sync UI (container browser and progress), unit tested.
 */

/** The next polling interval: doubles after a poll that showed no change, back to the base after a change. */
export function nextPollInterval(current: number, base: number, changed: boolean, max = 10000): number {
    if (changed) {
        return base;
    }

    return Math.min(max, Math.max(base, current * 2));
}

/**
 * Whether the live region may announce now: always for a final state, otherwise at most every `minGap` ms and only
 * when the text changed (a screen reader is not flooded).
 */
export function shouldAnnounce(now: number, lastAnnounced: number, text: string, lastText: string, terminal: boolean, minGap = 5000): boolean {
    if (text === '' || text === lastText) {
        return false;
    }

    return terminal || now - lastAnnounced >= minGap;
}

/** A message with %placeholders% replaced. */
export function fillMessage(template: string, params: Record<string, string>): string {
    return Object.entries(params).reduce((text, [key, value]) => text.split(`%${key}%`).join(value), template);
}

export interface ProgressPayload {
    status: string;
    terminal: boolean;
    processed: number;
    total: number | null;
    summary: string;
}

/** Reads the JSON of the progress endpoint defensively; null when it is not one. */
export function readProgress(data: unknown): ProgressPayload | null {
    if (typeof data !== 'object' || data === null) {
        return null;
    }
    const value = data as Record<string, unknown>;
    if (typeof value.status !== 'string') {
        return null;
    }

    return {
        status: value.status,
        terminal: value.terminal === true,
        processed: typeof value.processed === 'number' ? value.processed : 0,
        total: typeof value.total === 'number' && value.total > 0 ? value.total : null,
        summary: typeof value.summary === 'string' ? value.summary : '',
    };
}
