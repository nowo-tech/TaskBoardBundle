import { Application } from '@hotwired/stimulus';
import { afterAll, beforeAll, beforeEach, describe, expect, it } from 'vitest';
import FilterFoldController from './filter-fold-controller';
import { countActiveFilters, foldedKeys, openFold, readFilterFields } from './filter-fold-utils';

const tick = (): Promise<void> => new Promise((resolve) => setTimeout(resolve, 0));

const form = (collapsed: boolean): string => `<form id="f">
    <div class="nowo-ui-filters-collapse${collapsed ? ' is-collapsed' : ''}" data-controller="nowo-task-board-filter-fold"
        data-nowo-task-board-filter-fold-key-value="k"
        data-nowo-task-board-filter-fold-messages-value='{"collapse":"Fold","expand":"Unfold","none":"None","one":"One","many":"%count% on"}'>
        <button type="button" data-nowo-task-board-filter-fold-target="toggle" data-action="nowo-task-board-filter-fold#toggle" aria-expanded="${collapsed ? 'false' : 'true'}"></button>
        <p class="nowo-ui-filters-collapse__summary" data-nowo-task-board-filter-fold-target="summary"${collapsed ? '' : ' hidden'}></p>
    </div>
    <input name="search" value="">
    <select name="priority"><option value=""></option><option value="high">High</option></select>
    <input type="checkbox" name="time" data-nowo-task-filter-ignore checked>
</form>`;

describe('countActiveFilters', () => {
    it('counts values and checked boxes, never the ignored or hidden', () => {
        expect(countActiveFilters([
            { type: 'text', value: ' a ', checked: false, ignored: false },
            { type: 'text', value: '  ', checked: false, ignored: false },
            { type: 'checkbox', value: '1', checked: true, ignored: false },
            { type: 'checkbox', value: '1', checked: false, ignored: false },
            { type: 'checkbox', value: '1', checked: true, ignored: true },
            { type: 'hidden', value: 'x', checked: false, ignored: false },
            { type: 'submit', value: 'x', checked: false, ignored: false },
        ])).toBe(2);
    });

    it('reads no fields without a form', () => {
        expect(readFilterFields(null)).toEqual([]);
    });
});

describe('foldedKeys', () => {
    it('reads the keys, and a malformed cookie as none', () => {
        expect(foldedKeys('a=1; c=x%2Cy', 'c')).toEqual(['x', 'y']);
        expect(foldedKeys('a=1', 'c')).toEqual([]);
        expect(foldedKeys('c=%E0%A4%A', 'c')).toEqual([]);
    });
});

describe('openFold', () => {
    it('opens a folded card without touching the cookie', () => {
        document.cookie = 'nowo_ui_filters_collapsed=; max-age=0; path=/';
        document.body.innerHTML = form(true);
        openFold(document.querySelector('form'));
        const fold = document.querySelector('.nowo-ui-filters-collapse');
        expect(fold?.classList.contains('is-collapsed')).toBe(false);
        expect(fold?.querySelector('button')?.getAttribute('aria-expanded')).toBe('true');
        expect(fold?.querySelector<HTMLElement>('p')?.hidden).toBe(true);
        expect(document.cookie).not.toContain('nowo_ui_filters_collapsed');
    });

    it('leaves an open card, a card with no summary and no form alone', () => {
        document.body.innerHTML = form(false);
        openFold(document.querySelector('form'));
        expect(document.querySelector('button')?.getAttribute('aria-expanded')).toBe('true');
        document.body.innerHTML = '<form><div class="nowo-ui-filters-collapse is-collapsed"><button aria-expanded="false"></button></div></form>';
        openFold(document.querySelector('form'));
        expect(document.querySelector('button')?.getAttribute('aria-expanded')).toBe('true');
        expect(() => openFold(null)).not.toThrow();
    });
});

describe('FilterFoldController', () => {
    let application: Application;

    beforeAll(() => {
        application = Application.start();
        application.register('nowo-task-board-filter-fold', FilterFoldController);
    });

    afterAll(() => {
        application.stop();
    });

    beforeEach(async () => {
        document.cookie = 'nowo_ui_filters_collapsed=; max-age=0; path=/';
        document.body.innerHTML = form(false);
        await tick();
    });

    it('folds with the live count, remembers it, and unfolds', () => {
        const button = document.querySelector('button') as HTMLButtonElement;
        const summary = document.querySelector('p') as HTMLElement;
        (document.querySelector('input[name="search"]') as HTMLInputElement).value = 'x';

        button.click();
        expect(document.querySelector('.nowo-ui-filters-collapse')?.classList.contains('is-collapsed')).toBe(true);
        expect(button.getAttribute('aria-expanded')).toBe('false');
        expect(button.getAttribute('aria-label')).toBe('Unfold');
        expect(summary.textContent).toBe('One');
        expect(summary.hidden).toBe(false);
        expect(document.cookie).toContain('nowo_ui_filters_collapsed=k');

        (document.querySelector('select') as HTMLSelectElement).value = 'high';
        button.click();
        expect(button.getAttribute('aria-expanded')).toBe('true');
        expect(button.getAttribute('aria-label')).toBe('Fold');
        expect(summary.hidden).toBe(true);
        expect(document.cookie).not.toContain('nowo_ui_filters_collapsed=k');

        button.click();
        expect(summary.textContent).toBe('2 on');
    });

    it('says none', () => {
        const button = document.querySelector('button') as HTMLButtonElement;
        button.click();
        expect(document.querySelector('p')?.textContent).toBe('None');
        button.click();
    });
});
