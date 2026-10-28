/**
 * Since 1.15: the text match of the searchable task picker (case and accents ignored, every word must appear).
 */
export function normalize(text: string): string {
    return text.normalize('NFD').replace(/[̀-ͯ]/g, '').toLowerCase().trim();
}

export function matchesOption(label: string, query: string): boolean {
    const haystack = normalize(label);

    return normalize(query)
        .split(/\s+/)
        .filter((word) => word !== '')
        .every((word) => haystack.includes(word));
}
