import { Controller } from '@hotwired/stimulus';
import { ModalToggle } from './modal-toggle';

/**
 * Opens the add-task dialog with the selected column pre-filled (since 1.14 a native <dialog>; the Bootstrap
 * `.modal` markup of older template overrides keeps working).
 */
export default class AddTaskModalController extends Controller<HTMLElement> {
    static targets = ['modal', 'backdrop', 'title', 'columnField', 'titleInput'];

    static values = {
        titleFormat: { type: String, default: 'Add task to %column%' },
    };

    declare readonly modalTarget: HTMLElement | HTMLDialogElement;

    declare readonly backdropTarget: HTMLElement;

    declare readonly titleTarget: HTMLElement;

    declare readonly columnFieldTarget: HTMLSelectElement | HTMLInputElement;

    declare readonly titleInputTarget: HTMLInputElement;

    declare readonly hasBackdropTarget: boolean;

    declare readonly hasTitleTarget: boolean;

    declare readonly hasColumnFieldTarget: boolean;

    declare readonly hasTitleInputTarget: boolean;

    declare readonly titleFormatValue: string;

    private toggle: ModalToggle | null = null;

    open(event: Event): void {
        const button = event.currentTarget as HTMLElement;
        const columnId = button.dataset.columnId ?? '';
        const columnName = button.dataset.columnName ?? '';

        if (this.hasColumnFieldTarget) {
            this.columnFieldTarget.value = columnId;
        }

        if (this.hasTitleTarget) {
            this.titleTarget.textContent = this.titleFormatValue.replace('%column%', columnName);
        }

        this.modal().show();

        if (this.hasTitleInputTarget) {
            window.requestAnimationFrame(() => this.titleInputTarget.focus());
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
