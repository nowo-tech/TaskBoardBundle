import { Controller } from '@hotwired/stimulus';
import { ModalToggle } from './modal-toggle';

/**
 * "Change priority" dialog on the task detail page (since 1.14 a native <dialog>; the Bootstrap `.modal` markup of
 * older template overrides keeps working).
 */
export default class TaskPriorityModalController extends Controller<HTMLElement> {
    static targets = ['modal', 'backdrop', 'firstOption'];

    declare readonly modalTarget: HTMLElement | HTMLDialogElement;

    declare readonly backdropTarget: HTMLElement;

    declare readonly firstOptionTarget: HTMLInputElement;

    declare readonly hasBackdropTarget: boolean;

    declare readonly hasFirstOptionTarget: boolean;

    private toggle: ModalToggle | null = null;

    open(event: Event): void {
        event.preventDefault();
        this.modal().show();

        const selected = this.modalTarget.querySelector<HTMLInputElement>('input[name="priority"]:checked');
        const focusTarget = selected ?? (this.hasFirstOptionTarget ? this.firstOptionTarget : null);
        if (focusTarget !== null) {
            window.requestAnimationFrame(() => focusTarget.focus());
        }
    }

    close(): void {
        this.modal().hide();
    }

    closeOnBackdrop(event: Event): void {
        if (this.modal().isBackdrop(event.target)) {
            this.close();
        }
    }

    /** Since 1.14: the picked option is highlighted at once (the label carries `is-selected`). */
    select(event: Event): void {
        const input = event.currentTarget as HTMLInputElement;
        this.modalTarget.querySelectorAll('.nowo-task-detail__priority-option').forEach((option) => {
            option.classList.toggle('is-selected', option.contains(input));
        });
    }

    private modal(): ModalToggle {
        this.toggle ??= new ModalToggle(this.modalTarget, this.hasBackdropTarget ? this.backdropTarget : null, () => this.close());

        return this.toggle;
    }
}
