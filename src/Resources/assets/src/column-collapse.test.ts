import { beforeEach, describe, expect, it } from 'vitest';
import { COLLAPSE_KEY_PREFIX, installColumnCollapse, openColumnRename, restoreCollapsed, setCollapsed } from './column-collapse';

function board(): string {
    return `<div data-board-id="b1">
        <section data-nowo-task-board-target="column" data-column-id="c1">
            <details data-nowo-ui-menu open><details open><button type="button" data-nowo-task-column-collapse="c1" aria-expanded="true" data-label-collapse="Hide" data-label-expand="Show">Hide</button></details></details>
        </section>
        <button type="button" data-nowo-task-column-collapse="c9" aria-expanded="true">orphan</button>
    </div>`;
}

describe('column collapse (2.22.0)', () => {
    beforeEach(() => {
        window.localStorage.clear();
        document.body.innerHTML = board();
    });

    it('folds the column, relabels the entry and remembers it per board', () => {
        installColumnCollapse(document, window.localStorage);
        const button = document.querySelector<HTMLButtonElement>('[data-nowo-task-column-collapse="c1"]')!;
        button.click();

        const column = document.querySelector('section')!;
        expect(column.classList.contains('is-collapsed')).toBe(true);
        expect(button.getAttribute('aria-expanded')).toBe('false');
        expect(button.textContent).toBe('Show');
        expect(document.querySelector('details[data-nowo-ui-menu]')!.hasAttribute('open')).toBe(false);
        expect(JSON.parse(window.localStorage.getItem(COLLAPSE_KEY_PREFIX + 'b1')!)).toEqual({ c1: true });

        button.click();
        expect(column.classList.contains('is-collapsed')).toBe(false);
        expect(button.textContent).toBe('Hide');
        expect(JSON.parse(window.localStorage.getItem(COLLAPSE_KEY_PREFIX + 'b1')!)).toEqual({ c1: null });
    });

    it('restores the folded columns on load and after a Turbo visit', () => {
        window.localStorage.setItem(COLLAPSE_KEY_PREFIX + 'b1', JSON.stringify({ c1: true }));
        installColumnCollapse(document, window.localStorage);
        expect(document.querySelector('section')!.classList.contains('is-collapsed')).toBe(true);

        document.body.innerHTML = board();
        document.dispatchEvent(new Event('turbo:load'));
        expect(document.querySelector('section')!.classList.contains('is-collapsed')).toBe(true);
    });

    it('ignores an entry outside a column, other clicks, and a page without a board id', () => {
        installColumnCollapse(document, null);
        const orphan = document.querySelector<HTMLButtonElement>('[data-nowo-task-column-collapse="c9"]')!;
        orphan.click();
        expect(orphan.getAttribute('aria-expanded')).toBe('true');
        document.body.click();
        expect(setCollapsed(orphan, true)).toBeNull();

        document.body.innerHTML = '<section data-nowo-task-board-target="column"><button data-nowo-task-column-collapse="c1" aria-expanded="true">x</button></section>';
        restoreCollapsed(document, window.localStorage);
        const button = document.querySelector('button')!;
        button.click();
        expect(button.textContent).toBe('x');
        expect(window.localStorage.length).toBe(0);
    });

    it('renames through the header\'s own pencil button', () => {
        const doc = document.implementation.createHTMLDocument('board');
        doc.body.innerHTML = `<div data-board-id="b1"><section data-nowo-task-board-target="column">
            <button type="button" class="nowo-task-column__edit">pencil</button>
            <details data-nowo-ui-menu open><button type="button" data-nowo-task-column-rename="c1">Rename</button></details>
        </section><button type="button" data-nowo-task-column-rename="c2">Orphan</button></div>`;
        let clicks = 0;
        doc.querySelector('.nowo-task-column__edit')!.addEventListener('click', () => {
            clicks += 1;
        });
        installColumnCollapse(doc, null);
        doc.querySelector<HTMLButtonElement>('[data-nowo-task-column-rename="c1"]')!.click();
        expect(clicks).toBe(1);
        expect(doc.querySelector('details')!.hasAttribute('open')).toBe(false);
        expect(openColumnRename(doc.querySelector('[data-nowo-task-column-rename="c2"]')!)).toBe(false);
    });

    it('waits for the document when it is still loading', () => {
        const listeners: string[] = [];
        const fake = { readyState: 'loading', addEventListener: (type: string) => listeners.push(type) } as unknown as Document;
        installColumnCollapse(fake, null);
        expect(listeners).toEqual(['click', 'DOMContentLoaded', 'turbo:load']);
    });
});
