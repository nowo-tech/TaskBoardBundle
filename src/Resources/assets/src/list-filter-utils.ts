/**
 * Pure helpers for client-side list filtering (unit-tested).
 */
export function matchesListFilter(
    title: string,
    priority: string,
    columnName: string,
    search: string,
    priorityFilter: string,
    columnFilter: string,
): boolean {
    const normalizedSearch = search.trim().toLowerCase();
    if (normalizedSearch !== '' && !title.toLowerCase().includes(normalizedSearch)) {
        return false;
    }

    if (priorityFilter !== '' && priority !== priorityFilter) {
        return false;
    }

    if (columnFilter !== '' && columnName !== columnFilter) {
        return false;
    }

    return true;
}
