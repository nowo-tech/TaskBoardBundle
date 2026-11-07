import { Controller } from '@hotwired/stimulus';
import { openKitDialog } from './dialog-utils';

/** "Change priority" dialog on the task detail page, focused on the current priority. */
export default class TaskPriorityModalController extends Controller<HTMLElement> {
    static targets = ['modal', 'firstOption'];

    declare readonly modalTarget: HTMLDialogElement;

    declare readonly firstOptionTarget: HTMLInputElement;

    declare readonly hasFirstOptionTarget: boolean;

    open(event: Event): void {
        event.preventDefault();
        const selected = this.modalTarget.querySelector<HTMLInputElement>('input[name="priority"]:checked');
        openKitDialog(this.modalTarget, event.currentTarget as Element, selected ?? (this.hasFirstOptionTarget ? this.firstOptionTarget : null));
    }

    /** Since 1.14: the picked option is highlighted at once (the label carries `is-selected`). */
    select(event: Event): void {
        const input = event.currentTarget as HTMLInputElement;
        this.modalTarget.querySelectorAll('.nowo-task-detail__priority-option').forEach((option) => {
            option.classList.toggle('is-selected', option.contains(input));
        });
    }
}
