/**
 * Since 1.15: the folded list filters. A filter field is active when it holds a value (a checked box, a non-empty
 * text or select); fields marked `data-nowo-task-filter-ignore` (display options) never count.
 */
export type FilterField = { type: string; value: string; checked: boolean; ignored: boolean };

export function countActiveFilters(fields: FilterField[]): number {
    return fields.filter((field) => {
        if (field.ignored || field.type === 'hidden' || field.type === 'submit' || field.type === 'button') {
            return false;
        }

        return field.type === 'checkbox' || field.type === 'radio' ? field.checked : field.value.trim() !== '';
    }).length;
}

/** Open while a filter is active; otherwise the saved state (open when nothing was saved). */
export function resolveOpen(saved: unknown, activeCount: number): boolean {
    return activeCount > 0 || saved !== false;
}
