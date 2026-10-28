import { Controller } from '@hotwired/stimulus';
import { ModalToggle } from './modal-toggle';

/**
 * "Manage people" dialog on the task detail page (since 1.14 a native <dialog>; the Bootstrap `.modal` markup of
 * older template overrides keeps working).
 */
export default class TaskMembersModalController extends Controller<HTMLElement> {
    static targets = ['modal', 'backdrop', 'userField'];

    declare readonly modalTarget: HTMLElement | HTMLDialogElement;

    declare readonly backdropTarget: HTMLElement;

    declare readonly userFieldTarget: HTMLSelectElement;

    declare readonly hasBackdropTarget: boolean;

    declare readonly hasUserFieldTarget: boolean;

    private toggle: ModalToggle | null = null;

    open(event: Event): void {
        event.preventDefault();
        this.modal().show();

        if (this.hasUserFieldTarget) {
            window.requestAnimationFrame(() => this.userFieldTarget.focus());
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

    private modal(): ModalToggle {
        this.toggle ??= new ModalToggle(this.modalTarget, this.hasBackdropTarget ? this.backdropTarget : null, () => this.close());

        return this.toggle;
    }
}
