import { Controller } from '@hotwired/stimulus';
import { matchesListFilter } from './list-filter-utils';

/**
 * Client-side filters for the task list view.
 */
export default class ListFilterController extends Controller<HTMLElement> {
    static targets = ['search', 'priority', 'column', 'row'];

    declare readonly searchTarget: HTMLInputElement;

    declare readonly priorityTarget: HTMLSelectElement;

    declare readonly columnTarget: HTMLSelectElement;

    declare readonly rowTargets: HTMLElement[];

    connect(): void {
        this.applyFilters();
    }

    filter(): void {
        this.applyFilters();
    }

    private applyFilters(): void {
        const search = this.hasSearchTarget ? this.searchTarget.value : '';
        const priority = this.hasPriorityTarget ? this.priorityTarget.value : '';
        const column = this.hasColumnTarget ? this.columnTarget.value : '';

        this.rowTargets.forEach((row) => {
            const visible = matchesListFilter(
                row.dataset.title ?? '',
                row.dataset.priority ?? '',
                row.dataset.column ?? '',
                search,
                priority,
                column,
            );
            row.hidden = !visible;
        });
    }

    /** Since 1.13.1: the filters apply while typing; Enter does not submit the form (replaces an inline onsubmit). */
    prevent(event: Event): void {
        event.preventDefault();
    }
}
