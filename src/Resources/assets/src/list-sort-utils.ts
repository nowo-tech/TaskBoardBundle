/**
 * Since 1.15: the order of the list rows (title, priority — urgent first —, due date — empty last —, column); equal
 * rows keep their board order (`position`).
 */
export type SortRow = { title: string; priority: string; due: string; column: string; position: number; number?: number };

const PRIORITY_RANK: Record<string, number> = { urgent: 0, high: 1, normal: 2, low: 3 };

export function compareRows(a: SortRow, b: SortRow, key: string): number {
    let result = 0;
    if (key === 'title') {
        result = a.title.localeCompare(b.title, undefined, { sensitivity: 'base', numeric: true });
    } else if (key === 'priority') {
        result = (PRIORITY_RANK[a.priority] ?? 9) - (PRIORITY_RANK[b.priority] ?? 9);
    } else if (key === 'due') {
        result = a.due === b.due ? 0 : a.due === '' ? 1 : b.due === '' ? -1 : a.due < b.due ? -1 : 1;
    } else if (key === 'number') {
        // Since 2.1.0 (spec 214): a task with no number yet goes last.
        result = (a.number ?? Number.MAX_SAFE_INTEGER) - (b.number ?? Number.MAX_SAFE_INTEGER);
    } else if (key === 'column') {
        result = a.column.localeCompare(b.column, undefined, { sensitivity: 'base', numeric: true });
    }

    return result !== 0 ? result : a.position - b.position;
}

/** Reads a row's data-title, data-priority, data-due, data-column and data-number. */
export function readSortRow(dataset: DOMStringMap, position: number): SortRow {
    return {
        title: dataset.title ?? '',
        priority: dataset.priority ?? '',
        due: dataset.due ?? '',
        column: dataset.column ?? '',
        position,
        number: dataset.number !== undefined && dataset.number !== '' ? Number(dataset.number) : undefined,
    };
}
