import { Controller } from '@hotwired/stimulus';

/**
 * Opens the add-task modal with the selected column pre-filled.
 */
export default class AddTaskModalController extends Controller<HTMLElement> {
    static targets = ['modal', 'backdrop', 'title', 'columnField', 'titleInput'];

    static values = {
        titleFormat: { type: String, default: 'Add task to %column%' },
    };

    declare readonly modalTarget: HTMLElement;

    declare readonly backdropTarget: HTMLElement;

    declare readonly titleTarget: HTMLElement;

    declare readonly columnFieldTarget: HTMLSelectElement | HTMLInputElement;

    declare readonly titleInputTarget: HTMLInputElement;

    declare readonly hasBackdropTarget: boolean;

    declare readonly hasTitleTarget: boolean;

    declare readonly hasColumnFieldTarget: boolean;

    declare readonly hasTitleInputTarget: boolean;

    declare readonly titleFormatValue: string;

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

        this.showModal();

        if (this.hasTitleInputTarget) {
            window.requestAnimationFrame(() => this.titleInputTarget.focus());
        }
    }

    close(): void {
        this.hideModal();
    }

    closeOnBackdrop(event: Event): void {
        if (event.target === this.backdropTarget) {
            this.close();
        }
    }

    closeOnEscape(event: KeyboardEvent): void {
        if (event.key === 'Escape') {
            this.close();
        }
    }

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
