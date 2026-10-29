/**
 * Since 1.16: the @mention logic of the comment box (pure, unit-tested). A mention is typed as "@" followed by the
 * start of a person's name; picking a candidate writes "@Name " into the text and remembers the person's id, so the
 * server stores the mention structured (ids) and the text stays plain.
 */
export type MentionCandidate = { id: string; label: string };

export type MentionQuery = { start: number; query: string };

/** The "@…" being typed just before the caret (no whitespace inside, "@" at the start or after a space), or null. */
export function findMentionQuery(text: string, caret: number): MentionQuery | null {
    const before = text.slice(0, caret);
    const at = before.lastIndexOf('@');
    if (at < 0) {
        return null;
    }
    const query = before.slice(at + 1);
    if (/\s/.test(query) || query.length > 40 || (at > 0 && !/\s/.test(before.charAt(at - 1)))) {
        return null;
    }

    return { start: at, query };
}

function fold(text: string): string {
    return text.normalize('NFD').replace(/[̀-ͯ]/g, '').toLowerCase();
}

/** The candidates whose name contains the query (case and accents ignored), names starting with it first. */
export function filterCandidates(candidates: MentionCandidate[], query: string, limit = 8): MentionCandidate[] {
    const q = fold(query);

    return candidates
        .filter((candidate) => fold(candidate.label).includes(q))
        .sort((a, b) => Number(!fold(a.label).startsWith(q)) - Number(!fold(b.label).startsWith(q)) || a.label.localeCompare(b.label))
        .slice(0, limit);
}

/** Replaces the "@query" at `start…caret` with "@Label " and returns the new text and caret. */
export function insertMention(text: string, start: number, caret: number, label: string): { text: string; caret: number } {
    const inserted = `@${label} `;

    return { text: text.slice(0, start) + inserted + text.slice(caret), caret: start + inserted.length };
}

/** The picked people still written in the text ("@Label"), once each. */
export function keptMentions(text: string, picked: MentionCandidate[]): MentionCandidate[] {
    const seen = new Set<string>();

    return picked.filter((candidate) => {
        if (seen.has(candidate.id) || !text.includes(`@${candidate.label}`)) {
            return false;
        }
        seen.add(candidate.id);

        return true;
    });
}

/** The next active option index for ArrowDown / ArrowUp (wrapping), -1 without options. */
export function moveActive(current: number, count: number, delta: 1 | -1): number {
    if (count === 0) {
        return -1;
    }
    if (current < 0) {
        return delta > 0 ? 0 : count - 1;
    }

    return (current + delta + count) % count;
}

/** The people picked before (the hidden field of an edited comment): a JSON list of {id, label}. */
export function parsePicked(raw: string): MentionCandidate[] {
    try {
        const parsed = JSON.parse(raw) as unknown;
        if (!Array.isArray(parsed)) {
            return [];
        }

        return parsed.filter(
            (item): item is MentionCandidate =>
                item !== null && typeof item === 'object' && typeof (item as MentionCandidate).id === 'string' && typeof (item as MentionCandidate).label === 'string',
        );
    } catch {
        return [];
    }
}
