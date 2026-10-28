/**
 * Since 1.15: the Gantt scale remembered per board. A visit without `?scale=` goes to the scale saved for the board
 * (when it is another one); a visit with it saves it.
 */
export const GANTT_SCALES = ['hours', 'days', 'weeks', 'months'];

/** The URL to go to (the saved scale), or null to stay. */
export function scaleRedirect(href: string, current: string, saved: unknown): string | null {
    const url = new URL(href);
    if (url.searchParams.has('scale') || typeof saved !== 'string' || !GANTT_SCALES.includes(saved) || saved === current) {
        return null;
    }
    url.searchParams.set('scale', saved);

    return url.toString();
}

/** The scale to save for this visit: the one in the URL when it is valid, else null. */
export function scaleToRemember(href: string): string | null {
    const scale = new URL(href).searchParams.get('scale');

    return scale !== null && GANTT_SCALES.includes(scale) ? scale : null;
}
