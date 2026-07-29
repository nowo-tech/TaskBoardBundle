/**
 * Pure helpers for client-side list filtering (unit-tested).
 *
 * @param title - Task title text
 * @param priority - Task priority value
 * @param columnName - Column display name
 * @param search - Free-text search filter
 * @param priorityFilter - Selected priority filter (empty = any)
 * @param columnFilter - Selected column filter (empty = any)
 * @returns True when the row matches all active filters
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
