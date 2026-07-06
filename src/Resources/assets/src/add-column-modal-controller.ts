import { Controller } from '@hotwired/stimulus';

/**
 * Opens the add-column modal on the kanban board.
 */
export default class AddColumnModalController extends Controller<HTMLElement> {
    static targets = ['modal', 'backdrop', 'nameInput'];

    declare readonly modalTarget: HTMLElement;

    declare readonly backdropTarget: HTMLElement;

    declare readonly nameInputTarget: HTMLInputElement;

    declare readonly hasBackdropTarget: boolean;

    declare readonly hasNameInputTarget: boolean;

    open(): void {
        this.showModal();

        if (this.hasNameInputTarget) {
            window.requestAnimationFrame(() => this.nameInputTarget.focus());
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
