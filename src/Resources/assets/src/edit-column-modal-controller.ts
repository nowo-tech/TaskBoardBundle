import { Controller } from '@hotwired/stimulus';

/**
 * Opens the edit-column modal and posts the updated name / color / icon (since 1.11); the delete form (since 1.11)
 * posts to the column's delete URL after a confirmation.
 */
export default class EditColumnModalController extends Controller<HTMLElement> {
    static targets = ['modal', 'backdrop', 'form', 'nameInput', 'colorInput', 'iconInput', 'deleteForm', 'title'];

    static values = {
        updateUrlTemplate: String,
        deleteUrlTemplate: String,
        titleTemplate: String,
    };

    declare readonly iconInputTarget: HTMLSelectElement;

    declare readonly hasIconInputTarget: boolean;

    declare readonly deleteFormTarget: HTMLFormElement;

    declare readonly hasDeleteFormTarget: boolean;

    declare deleteUrlTemplateValue: string;

    declare readonly modalTarget: HTMLElement;

    declare readonly backdropTarget: HTMLElement;

    declare readonly formTarget: HTMLFormElement;

    declare readonly nameInputTarget: HTMLInputElement;

    declare readonly colorInputTarget: HTMLInputElement;

    declare readonly titleTarget: HTMLElement;

    declare readonly hasBackdropTarget: boolean;

    declare readonly hasColorInputTarget: boolean;

    declare updateUrlTemplateValue: string;

    declare titleTemplateValue: string;

    open(event: Event): void {
        const trigger = event.currentTarget as HTMLElement;
        const columnId = trigger.dataset.columnId ?? '';
        const columnName = trigger.dataset.columnName ?? '';
        const columnColor = trigger.dataset.columnColor ?? '#206bc4';

        if (columnId === '') {
            return;
        }

        this.formTarget.action = this.updateUrlTemplateValue.replace('COLUMN_ID', columnId);
        this.nameInputTarget.value = columnName;

        if (this.hasColorInputTarget) {
            this.colorInputTarget.value = columnColor !== '' ? columnColor : '#206bc4';
        }

        if (this.hasIconInputTarget) {
            this.iconInputTarget.value = trigger.dataset.columnIcon ?? '';
        }

        if (this.hasDeleteFormTarget && this.deleteUrlTemplateValue !== '') {
            this.deleteFormTarget.action = this.deleteUrlTemplateValue.replace('COLUMN_ID', columnId);
        }

        if (this.hasTitleTarget && this.titleTemplateValue !== '') {
            this.titleTarget.textContent = this.titleTemplateValue.replace('%column%', columnName);
        }

        this.showModal();
        window.requestAnimationFrame(() => this.nameInputTarget.focus());
    }

    confirmDelete(event: Event): void {
        const message = (event.currentTarget as HTMLElement).dataset.confirm ?? '';
        if (message !== '' && !window.confirm(message)) {
            event.preventDefault();
        }
    }

    stopDrag(event: Event): void {
        event.stopPropagation();
    }

    close(): void {
        this.hideModal();
    }

    closeOnBackdrop(event: Event): void {
        if (event.target === this.backdropTarget) {
            this.close();
        }
    }

    closeOnEscape = (event: KeyboardEvent): void => {
        if (event.key === 'Escape') {
            this.close();
        }
    };

    private showModal(): void {
        this.modalTarget.classList.add('show');
        this.modalTarget.style.display = 'block';
        this.modalTarget.removeAttribute('aria-hidden');
        this.modalTarget.setAttribute('aria-modal', 'true');
        document.body.classList.add('modal-open');

        if (this.hasBackdropTarget) {
            this.backdropTarget.classList.add('show');
        }

        document.addEventListener('keydown', this.closeOnEscape);
    }

    private hideModal(): void {
        this.modalTarget.classList.remove('show');
        this.modalTarget.style.display = 'none';
        this.modalTarget.setAttribute('aria-hidden', 'true');
        this.modalTarget.removeAttribute('aria-modal');
        document.body.classList.remove('modal-open');

        if (this.hasBackdropTarget) {
            this.backdropTarget.classList.remove('show');
        }

        document.removeEventListener('keydown', this.closeOnEscape);
    }
}
