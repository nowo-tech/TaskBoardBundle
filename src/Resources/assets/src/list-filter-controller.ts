import { Controller } from '@hotwired/stimulus';
import { matchesListFilter } from './list-filter-utils';
import { compareRows, readSortRow } from './list-sort-utils';
import { readMap, writeEntry } from './storage';
import { formatDeviation, formatMinutes, readTimeRow, sumTimes } from './time-utils';

const TIME_COLUMNS_KEY = 'nowo-task-board.list-time-columns';

/**
 * Client-side filters for the task list view. Since 1.15 also "Over estimate", the optional Estimate / Spent /
 * Deviation columns (remembered per board) and the totals of the rows the filters show (`totals` targets: a group's
 * <tfoot> sums its table, the list's summary sums every shown row).
 */
export default class ListFilterController extends Controller<HTMLElement> {
    static targets = ['search', 'priority', 'column', 'row', 'overEstimate', 'timeColumns', 'totals', 'sort'];

    static values = { board: String };

    declare readonly searchTarget: HTMLInputElement;

    declare readonly priorityTarget: HTMLSelectElement;

    declare readonly columnTarget: HTMLSelectElement;

    declare readonly overEstimateTarget: HTMLInputElement;

    declare readonly timeColumnsTarget: HTMLInputElement;

    declare readonly sortTarget: HTMLSelectElement;

    declare readonly hasSortTarget: boolean;

    declare readonly rowTargets: HTMLElement[];

    declare readonly totalsTargets: HTMLElement[];

    declare readonly hasSearchTarget: boolean;

    declare readonly hasPriorityTarget: boolean;

    declare readonly hasColumnTarget: boolean;

    declare readonly hasOverEstimateTarget: boolean;

    declare readonly hasTimeColumnsTarget: boolean;

    declare readonly boardValue: string;

    connect(): void {
        if (this.hasTimeColumnsTarget && readMap(TIME_COLUMNS_KEY)[this.boardValue] === true) {
            this.timeColumnsTarget.checked = true;
        }
        this.showTimeColumns();
        this.applyFilters();
    }

    filter(): void {
        this.applyFilters();
    }

    toggleTimeColumns(): void {
        this.showTimeColumns();
        writeEntry(TIME_COLUMNS_KEY, this.boardValue, this.hasTimeColumnsTarget && this.timeColumnsTarget.checked);
    }

    /** Since 1.15: orders the rows of every group (title, priority, due date, column; empty = the board order). */
    sort(): void {
        const key = this.hasSortTarget ? this.sortTarget.value : '';
        const bodies = new Set(this.rowTargets.map((row) => row.parentElement).filter((body): body is HTMLElement => body !== null));
        for (const body of bodies) {
            const rows = Array.from(body.children).filter((child): child is HTMLElement => child instanceof HTMLElement && this.rowTargets.includes(child));
            rows.forEach((row, index) => {
                row.dataset.position ??= String(index);
            });
            rows
                .map((row) => ({ row, data: readSortRow(row.dataset, Number(row.dataset.position)) }))
                .sort((a, b) => compareRows(a.data, b.data, key))
                .forEach(({ row }) => body.appendChild(row));
        }
    }

    /** Since 1.13.1: the filters apply while typing; Enter does not submit the form (replaces an inline onsubmit). */
    prevent(event: Event): void {
        event.preventDefault();
    }

    private showTimeColumns(): void {
        this.element.classList.toggle('is-time-visible', this.hasTimeColumnsTarget && this.timeColumnsTarget.checked);
    }

    private applyFilters(): void {
        const search = this.hasSearchTarget ? this.searchTarget.value : '';
        const priority = this.hasPriorityTarget ? this.priorityTarget.value : '';
        const column = this.hasColumnTarget ? this.columnTarget.value : '';
        const overOnly = this.hasOverEstimateTarget && this.overEstimateTarget.checked;

        this.rowTargets.forEach((row) => {
            const visible = matchesListFilter(
                row.dataset.title ?? '',
                row.dataset.priority ?? '',
                row.dataset.column ?? '',
                search,
                priority,
                column,
                overOnly,
                row.dataset.over === '1',
            );
            row.hidden = !visible;
        });

        this.updateTotals();
    }

    private updateTotals(): void {
        for (const totals of this.totalsTargets) {
            const scope = totals.closest('table') ?? this.element;
            const rows = this.rowTargets.filter((row) => !row.hidden && scope.contains(row)).map((row) => readTimeRow(row.dataset));
            const sums = sumTimes(rows);
            this.paint(totals, 'estimate', formatMinutes(sums.estimate));
            this.paint(totals, 'spent', formatMinutes(sums.spent));
            this.paint(totals, 'deviation', formatDeviation(sums)).dataset.status = sums.status;
        }
    }

    private paint(totals: HTMLElement, name: string, text: string): HTMLElement {
        const cell = totals.querySelector<HTMLElement>(`[data-total="${name}"]`) ?? document.createElement('span');
        cell.textContent = text;

        return cell;
    }
}
