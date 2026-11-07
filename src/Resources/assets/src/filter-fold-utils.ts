/**
 * Since 2.0.0: the list's filter fold. A field is active when it holds a value (text, select) or is checked; fields marked
 * `data-nowo-task-filter-ignore` (display options) and hidden fields never count.
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

export function readFilterFields(form: HTMLFormElement | null): FilterField[] {
    const fields: FilterField[] = [];
    for (const element of form === null ? [] : Array.from(form.elements)) {
        if (element instanceof HTMLInputElement || element instanceof HTMLSelectElement || element instanceof HTMLTextAreaElement) {
            fields.push({
                type: element.type,
                value: element.value,
                checked: element instanceof HTMLInputElement && element.checked,
                ignored: element.name === '' || element.hasAttribute('data-nowo-task-filter-ignore'),
            });
        }
    }

    return fields;
}

/**
 * Opens a folded filter card, whichever controller drives it: the bundle's, or a host's `filters-collapse` peer. Their markup
 * is the same. The cookie is not touched: the card is open because a filter is active, not because the user chose it.
 */
export function openFold(form: HTMLFormElement | null): void {
    const fold = form?.querySelector<HTMLElement>(':scope > .nowo-ui-filters-collapse.is-collapsed');
    if (fold === null || fold === undefined) {
        return;
    }
    fold.classList.remove('is-collapsed');
    fold.querySelector('button')?.setAttribute('aria-expanded', 'true');
    const summary = fold.querySelector<HTMLElement>('.nowo-ui-filters-collapse__summary');
    if (summary !== null) {
        summary.hidden = true;
    }
}

/** Keeps the most recent keys, so the cookie stays under the browser's limit (as UiKit's peer does). */
export function foldedKeys(cookie: string, name: string): string[] {
    const raw = cookie.split('; ').find((part) => part.startsWith(`${name}=`))?.slice(name.length + 1) ?? '';
    try {
        return decodeURIComponent(raw).split(',').filter((key) => key !== '');
    } catch {
        return [];
    }
}
