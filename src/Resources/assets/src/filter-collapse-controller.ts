import { Controller } from '@hotwired/stimulus';
import { countActiveFilters, resolveOpen, type FilterField } from './filter-collapse-utils';
import { readMap, writeEntry } from './storage';

const STORAGE_KEY = 'nowo-task-board.list-filters';

/**
 * Since 1.15: folds the list filters behind their toggle, like UiKit's collapse-panel (one localStorage map of
 * `{ [board id]: open }`, `aria-expanded` on the `button`, `is-collapsed` on the element, `body` hidden) — and opens
 * while a filter is active. The `count` target shows how many filters are active.
 */
export default class FilterCollapseController extends Controller<HTMLElement> {
    static targets = ['button', 'body', 'count'];

    static values = { id: String };

    declare readonly buttonTarget: HTMLElement;

    declare readonly bodyTarget: HTMLElement;

    declare readonly countTarget: HTMLElement;

    declare readonly hasCountTarget: boolean;

    declare readonly idValue: string;

    connect(): void {
        this.apply(resolveOpen(readMap(STORAGE_KEY)[this.idValue], this.activeCount()));
        this.bodyTarget.addEventListener('input', this.refresh);
        this.bodyTarget.addEventListener('change', this.refresh);
    }

    disconnect(): void {
        this.bodyTarget.removeEventListener('input', this.refresh);
        this.bodyTarget.removeEventListener('change', this.refresh);
    }

    toggle(event?: Event): void {
        event?.preventDefault();
        const open = this.element.classList.contains('is-collapsed');
        this.apply(open);
        writeEntry(STORAGE_KEY, this.idValue, open);
    }

    private refresh = (): void => {
        this.paintCount(this.activeCount());
    };

    private apply(open: boolean): void {
        this.element.classList.toggle('is-collapsed', !open);
        this.buttonTarget.setAttribute('aria-expanded', open ? 'true' : 'false');
        this.bodyTarget.hidden = !open;
        this.paintCount(this.activeCount());
    }

    private paintCount(count: number): void {
        if (this.hasCountTarget) {
            this.countTarget.textContent = String(count);
            this.countTarget.hidden = count === 0;
        }
    }

    private activeCount(): number {
        const fields: FilterField[] = Array.from(this.bodyTarget.querySelectorAll<HTMLInputElement | HTMLSelectElement>('input, select')).map((field) => ({
            type: field instanceof HTMLInputElement ? field.type : 'select',
            value: field.value,
            checked: field instanceof HTMLInputElement && field.checked,
            ignored: field.hasAttribute('data-nowo-task-filter-ignore'),
        }));

        return countActiveFilters(fields);
    }
}
