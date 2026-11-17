import { readMap, safeStorage, writeEntry } from './storage';

/**
 * Since 2.22.0: "Hide / collapse" of a column header's menu folds the column on this board, in this browser only (a view
 * setting: nothing is written on the server). The folded columns are remembered per board in localStorage
 * (`nowo-task-board:collapsed:<board id>`, column id => true) and folded again on load and after a Turbo visit. The entry's
 * label and `aria-expanded` follow the state, so the context menu, which reads the kebab when it opens, says the right thing.
 */
export const COLLAPSE_KEY_PREFIX = 'nowo-task-board:collapsed:';

const COLUMN = '[data-nowo-task-board-target="column"]';

function boardIdOf(element: Element): string {
    return element.closest('[data-board-id]')?.getAttribute('data-board-id') ?? '';
}

/** Folds or unfolds one column and updates its menu entry; returns the column, or null when the button has none. */
export function setCollapsed(button: Element, collapsed: boolean): HTMLElement | null {
    const column = button.closest<HTMLElement>(COLUMN);
    if (column === null) {
        return null;
    }
    column.classList.toggle('is-collapsed', collapsed);
    button.setAttribute('aria-expanded', collapsed ? 'false' : 'true');
    const label = button.getAttribute(collapsed ? 'data-label-expand' : 'data-label-collapse');
    if (label !== null && label !== '') {
        button.textContent = label;
    }

    return column;
}

/** Folds again the columns remembered for each board under `root`. */
export function restoreCollapsed(root: ParentNode, storage: Storage | null = safeStorage()): void {
    root.querySelectorAll('[data-nowo-task-column-collapse]').forEach((button) => {
        const boardId = boardIdOf(button);
        const columnId = button.getAttribute('data-nowo-task-column-collapse') ?? '';
        if (boardId !== '' && columnId !== '' && readMap(COLLAPSE_KEY_PREFIX + boardId, storage)[columnId] === true) {
            setCollapsed(button, true);
        }
    });
}

/**
 * "Rename" of a column header's menu opens the column dialog through the header's own pencil button, so the dialog reads
 * the column from the one element that carries it. Returns whether there was a pencil to click.
 */
export function openColumnRename(entry: Element): boolean {
    const pencil = entry.closest(COLUMN)?.querySelector<HTMLElement>('.nowo-task-column__edit') ?? null;
    entry.closest('details[data-nowo-ui-menu]')?.removeAttribute('open');
    pencil?.click();

    return pencil !== null;
}

/** One delegated listener: the menu entry (or the context menu's forwarded click) toggles its column, or renames it. */
export function installColumnCollapse(root: Document, storage: Storage | null = safeStorage()): void {
    root.addEventListener('click', (event: Event) => {
        const rename = event.target instanceof Element ? event.target.closest('[data-nowo-task-column-rename]') : null;
        if (rename !== null) {
            event.preventDefault();
            openColumnRename(rename);

            return;
        }
        const button = event.target instanceof Element ? event.target.closest('[data-nowo-task-column-collapse]') : null;
        if (button === null) {
            return;
        }
        event.preventDefault();
        const collapsed = button.getAttribute('aria-expanded') !== 'false';
        if (setCollapsed(button, collapsed) === null) {
            return;
        }
        const boardId = boardIdOf(button);
        const columnId = button.getAttribute('data-nowo-task-column-collapse') ?? '';
        if (boardId !== '' && columnId !== '') {
            writeEntry(COLLAPSE_KEY_PREFIX + boardId, columnId, collapsed ? true : null, storage);
        }
        // Close the kebab the entry sits in (a <details> menu).
        button.closest('details')?.removeAttribute('open');
        button.closest('details[data-nowo-ui-menu]')?.removeAttribute('open');
    });
    const restore = (): void => restoreCollapsed(root, storage);
    if (root.readyState === 'loading') {
        root.addEventListener('DOMContentLoaded', restore);
    } else {
        restore();
    }
    root.addEventListener('turbo:load', restore);
}
