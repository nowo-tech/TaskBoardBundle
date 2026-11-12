import { Controller } from '@hotwired/stimulus';
import { dayDiff, dragEdge, grabOffset, gridStep, movedDates, phoneListUrl, sameDates, snapToWorkingDay, type CalendarEdge, type TaskDates } from './calendar-utils';
import { openKitDialog } from './dialog-utils';
import { openFold } from './filter-fold-utils';

type SortableDetail = { id: string; listId: string; fromListId: string; source: string; item: HTMLElement; revert?: () => boolean };

const PHONE = '(max-width: 40rem)';
const COARSE = '(pointer: coarse)';
const TOAST_MS = 8000;

/**
 * Since 2.1.0 (spec 214): the calendar's writes. The body of a bar is dragged by UiKit's `sortable` (which also gives Alt+arrows),
 * its edges here, because the kit reorders items and has no resize. Every route posts the same request to `task_schedule`; the
 * server answers for the permission. There is no drag on a phone or a touch screen: Reschedule and the panel do it (FR-031).
 */
export default class CalendarController extends Controller<HTMLElement> {
    static targets = ['body', 'grid', 'dialog', 'form', 'startField', 'dueField', 'dialogError', 'toastSuccess', 'toastError'];

    static values = {
        csrfToken: String,
        failed: String,
        undo: String,
        undone: String,
        filtered: Boolean,
        // Since 2.14.0: `calendar.skip_non_working_days` (with a day annotation provider).
        skipNonWorking: Boolean,
    };

    declare readonly bodyTarget: HTMLElement;

    declare readonly hasGridTarget: boolean;

    declare readonly gridTarget: HTMLElement;

    declare readonly hasDialogTarget: boolean;

    declare readonly dialogTarget: HTMLDialogElement;

    declare readonly formTarget: HTMLFormElement;

    declare readonly startFieldTarget: HTMLInputElement;

    declare readonly dueFieldTarget: HTMLInputElement;

    declare readonly dialogErrorTarget: HTMLElement;

    declare readonly toastSuccessTarget: HTMLTemplateElement;

    declare readonly toastErrorTarget: HTMLTemplateElement;

    declare readonly csrfTokenValue: string;

    declare readonly failedValue: string;

    declare readonly undoValue: string;

    declare readonly undoneValue: string;

    declare readonly filteredValue: boolean;

    declare readonly skipNonWorkingValue: boolean;

    private grab = 0;

    private edge: { chip: HTMLElement; edge: CalendarEdge; pointerId: number; day: string | null } | null = null;

    connect(): void {
        const target = phoneListUrl(window.location.href, window.matchMedia(PHONE).matches);
        if (target !== null) {
            window.location.replace(target);

            return;
        }
        if (this.filteredValue) {
            openFold(this.element.querySelector('form'));
        }
        this.element.addEventListener('nowo-ui:sortable:start', this.onSortableStart);
        this.element.addEventListener('nowo-ui:sortable:move', this.onSortableMove);
        this.element.addEventListener('nowo-ui:sortable:moved', this.onSortableMoved);
        this.element.addEventListener('pointerdown', this.onPointerDown, true);
        this.element.addEventListener('keydown', this.onKeydown);
    }

    disconnect(): void {
        this.element.removeEventListener('nowo-ui:sortable:start', this.onSortableStart);
        this.element.removeEventListener('nowo-ui:sortable:move', this.onSortableMove);
        this.element.removeEventListener('nowo-ui:sortable:moved', this.onSortableMoved);
        this.element.removeEventListener('pointerdown', this.onPointerDown, true);
        this.element.removeEventListener('keydown', this.onKeydown);
        this.endEdge();
    }

    /** No drag on a phone or a touch screen. */
    private readonly onSortableStart = (event: Event): void => {
        if (window.matchMedia(PHONE).matches || window.matchMedia(COARSE).matches) {
            event.preventDefault();
        }
    };

    /** The day under the pointer, inside the bar that was grabbed: a long bar moves by what the hand moved. */
    private readonly onPointerDown = (event: PointerEvent): void => {
        const chip = (event.target as Element).closest<HTMLElement>('.nowo-task-cal__chip');
        if (chip !== null) {
            const box = chip.getBoundingClientRect();
            this.grab = grabOffset(event.clientX, box.left, box.width, Number(chip.dataset.span ?? 1));
        }
    };

    private days(detail: SortableDetail): number {
        return dayDiff(detail.fromListId, detail.listId) - (detail.source === 'pointer' ? this.grab : 0);
    }

    /** Reordering inside a day means nothing, and neither does a drop on the day it was held by. */
    private readonly onSortableMove = (event: Event): void => {
        const { detail } = event as CustomEvent<SortableDetail>;
        if (detail.listId === detail.fromListId || this.days(detail) === 0) {
            event.preventDefault();
        }
    };

    private readonly onSortableMoved = (event: Event): void => {
        const { detail } = event as CustomEvent<SortableDetail>;
        const dates = this.datesOf(detail.item);
        const next = movedDates(dates, this.days(detail), this.nonWorkingDays());
        this.grab = 0;
        if (next === null) {
            // Since 2.14.1 (audit NN-L2): it snapped back onto its own dates: the bar goes back, no write, no Undo.
            detail.revert?.();

            return;
        }
        void this.send(detail.item, next, dates, detail.revert);
    };

    /** FR-031: arrow keys, Home and End move between the days; the links and buttons inside a day keep their own keys. */
    private readonly onKeydown = (event: KeyboardEvent): void => {
        const cell = event.target;
        if (!this.hasGridTarget || !(cell instanceof HTMLElement) || cell.getAttribute('role') !== 'gridcell' || event.altKey || event.ctrlKey || event.metaKey) {
            return;
        }
        const cells = Array.from(this.gridTarget.querySelectorAll<HTMLElement>('[role="gridcell"]'));
        const next = gridStep(event.key, cells.indexOf(cell), cells.length);
        if (next !== null) {
            event.preventDefault();
            cell.tabIndex = -1;
            cells[next].tabIndex = 0;
            cells[next].focus();
        }
    };

    /** FR-027: an edge follows the pointer over the days and writes when it is released. Escape leaves everything as it was. */
    startEdge(event: PointerEvent): void {
        const handle = event.currentTarget as HTMLElement;
        const chip = handle.closest<HTMLElement>('.nowo-task-cal__chip');
        if (chip === null || event.pointerType === 'touch' || event.button !== 0) {
            return;
        }
        event.preventDefault();
        event.stopPropagation();
        this.edge = { chip, edge: handle.dataset.edge === 'start' ? 'start' : 'due', pointerId: event.pointerId, day: null };
        window.addEventListener('pointermove', this.onEdgeMove);
        window.addEventListener('pointerup', this.onEdgeUp);
        window.addEventListener('pointercancel', this.onEdgeCancel);
        window.addEventListener('keydown', this.onEdgeKey, true);
    }

    private readonly onEdgeMove = (event: PointerEvent): void => {
        if (this.edge === null || event.pointerId !== this.edge.pointerId) {
            return;
        }
        const day = this.dayAt(event.clientX, event.clientY);
        if (day !== null && day !== this.edge.day) {
            this.edge.day = day;
            this.paintRange(dragEdge(this.datesOf(this.edge.chip), this.edge.edge, this.snapDay(day)));
        }
    };

    private readonly onEdgeUp = (event: PointerEvent): void => {
        if (this.edge === null || event.pointerId !== this.edge.pointerId) {
            return;
        }
        const { chip, edge, day } = this.edge;
        this.endEdge();
        if (day !== null) {
            const dates = this.datesOf(chip);
            const next = dragEdge(dates, edge, this.snapDay(day));
            if (!sameDates(dates, next)) {
                void this.send(chip, next, dates);
            }
        }
    };

    private readonly onEdgeCancel = (): void => {
        this.endEdge();
    };

    private readonly onEdgeKey = (event: KeyboardEvent): void => {
        if (event.key === 'Escape') {
            event.stopPropagation();
            this.endEdge();
        }
    };

    private endEdge(): void {
        this.edge = null;
        window.removeEventListener('pointermove', this.onEdgeMove);
        window.removeEventListener('pointerup', this.onEdgeUp);
        window.removeEventListener('pointercancel', this.onEdgeCancel);
        window.removeEventListener('keydown', this.onEdgeKey, true);
        this.paintRange(null);
    }

    /** Since 2.14.0: the viewer's non-working days of the grid, when a drop snaps to working days. */
    private nonWorkingDays(): Set<string> {
        if (!this.skipNonWorkingValue || !this.hasGridTarget) {
            return new Set();
        }

        return new Set(Array.from(this.gridTarget.querySelectorAll<HTMLElement>('[data-non-working]'), (cell) => cell.dataset.date ?? ''));
    }

    private snapDay(day: string): string {
        return snapToWorkingDay(day, this.nonWorkingDays());
    }

    private dayAt(x: number, y: number): string | null {
        if (!this.hasGridTarget) {
            return null;
        }
        for (const cell of this.gridTarget.querySelectorAll<HTMLElement>('[role="gridcell"]')) {
            const box = cell.getBoundingClientRect();
            if (x >= box.left && x < box.right && y >= box.top && y < box.bottom) {
                return cell.dataset.date ?? null;
            }
        }

        return null;
    }

    private paintRange(dates: TaskDates | null): void {
        if (!this.hasGridTarget) {
            return;
        }
        this.gridTarget.querySelectorAll<HTMLElement>('[role="gridcell"]').forEach((cell) => {
            const day = cell.dataset.date ?? '';
            cell.classList.toggle('is-drop-range', dates !== null && day >= dates.start && day <= dates.due);
        });
    }

    /** FR-031: the dialog that needs no pointer. */
    openReschedule(event: Event): void {
        const button = event.currentTarget as HTMLElement;
        const task = button.closest<HTMLElement>('[data-schedule-url]');
        if (task === null || !this.hasDialogTarget) {
            return;
        }
        const dates = this.datesOf(task);
        this.formTarget.action = task.dataset.scheduleUrl ?? '';
        this.formTarget.dataset.taskId = task.dataset.taskId ?? '';
        this.formTarget.dataset.previousStart = dates.start;
        this.formTarget.dataset.previousDue = dates.due;
        this.startFieldTarget.value = dates.start;
        this.dueFieldTarget.value = dates.due;
        this.dialogErrorTarget.hidden = true;
        openKitDialog(this.dialogTarget, button, this.startFieldTarget);
    }

    submitSchedule(event: SubmitEvent): void {
        event.preventDefault();
        const next = { start: this.startFieldTarget.value, due: this.dueFieldTarget.value };
        const previous = { start: this.formTarget.dataset.previousStart ?? '', due: this.formTarget.dataset.previousDue ?? '' };
        void this.post(this.formTarget.action, next).then((answer) => {
            if (!answer.ok) {
                this.dialogErrorTarget.textContent = answer.message;
                this.dialogErrorTarget.hidden = false;

                return;
            }
            if (window.nowoUiCloseDialog !== undefined) {
                window.nowoUiCloseDialog(this.dialogTarget);
            } else {
                this.dialogTarget.close();
            }
            this.afterWrite(answer.message, this.formTarget.action, this.formTarget.dataset.taskId ?? '', previous);
        });
    }

    private datesOf(task: HTMLElement): TaskDates {
        return { start: task.dataset.start ?? '', due: task.dataset.due ?? '' };
    }

    private async send(task: HTMLElement, next: TaskDates, previous: TaskDates, revert?: () => boolean): Promise<void> {
        const url = task.dataset.scheduleUrl ?? '';
        const answer = await this.post(url, next);
        if (!answer.ok) {
            // FR-029: the chip goes back, and the error says why.
            revert?.();
            this.toast(this.toastErrorTarget, answer.message);

            return;
        }
        this.afterWrite(answer.message, url, task.dataset.taskId ?? '', previous);
    }

    private afterWrite(message: string, url: string, taskId: string, previous: TaskDates): void {
        this.toast(this.toastSuccessTarget, message, () => {
            void this.post(url, previous).then((answer) => {
                this.toast(answer.ok ? this.toastSuccessTarget : this.toastErrorTarget, answer.ok ? this.undoneValue : answer.message);
                if (answer.ok) {
                    void this.refresh(taskId);
                }
            });
        });
        void this.refresh(taskId);
    }

    private async post(url: string, dates: TaskDates): Promise<{ ok: boolean; message: string }> {
        const body = new FormData();
        body.set('start', dates.start);
        body.set('due', dates.due);
        body.set('_token', this.csrfTokenValue);

        try {
            const response = await fetch(url, { method: 'POST', body, headers: { 'X-Requested-With': 'XMLHttpRequest', Accept: 'application/json' }, credentials: 'same-origin' });
            const data = (await response.json().catch(() => ({}))) as { message?: unknown };

            return { ok: response.ok, message: typeof data.message === 'string' ? data.message : this.failedValue };
        } catch {
            return { ok: false, message: this.failedValue };
        }
    }

    /** Draws the days again from the server, which placed the bars; a page that cannot be read is loaded in full. */
    private async refresh(taskId: string): Promise<void> {
        try {
            const response = await fetch(window.location.href, { credentials: 'same-origin', headers: { Accept: 'text/html' } });
            const page = new DOMParser().parseFromString(await response.text(), 'text/html');
            const body = page.querySelector('[data-nowo-task-board-calendar-target="body"]');
            if (!response.ok || body === null) {
                window.location.reload();

                return;
            }
            const active = document.activeElement;
            const hadFocus = active !== null && this.bodyTarget.contains(active);
            this.bodyTarget.replaceChildren(...Array.from(body.childNodes).map((node) => document.importNode(node, true)));
            if (hadFocus || active === document.body) {
                this.bodyTarget.querySelector<HTMLElement>(`[data-task-id="${CSS.escape(taskId)}"] a`)?.focus({ preventScroll: true });
            }
        } catch {
            window.location.reload();
        }
    }

    /** FR-029, FR-031: the kit's toast (a live region), with Undo for a short time only. */
    private toast(template: HTMLTemplateElement, message: string, undo?: () => void): void {
        const stack = template.content.firstElementChild?.cloneNode(true) as HTMLElement | undefined;
        const toast = stack?.querySelector<HTMLElement>('[data-nowo-ui-toast]');
        if (stack === undefined || toast === null || toast === undefined) {
            return;
        }
        const text = toast.querySelector('.nowo-ui-toast__message');
        if (text !== null) {
            text.textContent = message;
        }
        if (undo !== undefined) {
            const button = document.createElement('button');
            button.type = 'button';
            button.className = 'nowo-task-cal__undo';
            button.textContent = this.undoValue;
            button.addEventListener('click', () => {
                undo();
                this.dismiss(toast);
            }, { once: true });
            text?.after(button);
        }
        toast.setAttribute('data-nowo-ui-toast-armed', '');
        const existing = document.querySelector('[data-nowo-ui-toast-stack]');
        if (existing !== null) {
            existing.append(toast);
        } else {
            document.body.append(stack);
        }
        window.setTimeout(() => this.dismiss(toast), TOAST_MS);
    }

    private dismiss(toast: HTMLElement): void {
        if (!toast.isConnected) {
            return;
        }
        if (window.nowoUiDismissToast !== undefined) {
            window.nowoUiDismissToast(toast);
        } else {
            toast.remove();
        }
    }
}

declare global {
    interface Window {
        nowoUiCloseDialog?: (id: string | HTMLDialogElement | null) => void;
        nowoUiDismissToast?: (toast: HTMLElement | null) => void;
    }
}

