import { Controller } from '@hotwired/stimulus';

/**
 * Opens the add-column modal on the kanban board.
 */
export default class AddColumnModalController extends Controller<HTMLElement> {
    static targets = ['modal', 'backdrop', 'nameInput'];

    declare readonly modalTarget: HTMLElement | HTMLDialogElement;

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

    private closeOnDialogBackdrop = (event: MouseEvent): void => {
        if (event.target === this.modalTarget) {
            this.close();
        }
    };

    closeOnEscape = (event: KeyboardEvent): void => {
        if (event.key === 'Escape') {
            this.close();
        }
    };

    private showModal(): void {
        // Since 1.13 the dialogs are native <dialog> elements (top layer, Escape and the focus trap from the browser).
        if (this.modalTarget instanceof HTMLDialogElement) {
            if (!this.modalTarget.open) {
                this.modalTarget.addEventListener('click', this.closeOnDialogBackdrop);
                this.modalTarget.showModal();
            }

            return;
        }
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
        if (this.modalTarget instanceof HTMLDialogElement) {
            this.modalTarget.removeEventListener('click', this.closeOnDialogBackdrop);
            this.modalTarget.close();

            return;
        }
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
