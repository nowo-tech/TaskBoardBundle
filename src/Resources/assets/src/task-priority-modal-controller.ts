import { Controller } from '@hotwired/stimulus';

/**
 * Edit task priority modal on the task detail page.
 */
export default class TaskPriorityModalController extends Controller<HTMLElement> {
    static targets = ['modal', 'backdrop', 'firstOption'];

    declare readonly modalTarget: HTMLElement;

    declare readonly backdropTarget: HTMLElement;

    declare readonly firstOptionTarget: HTMLInputElement;

    declare readonly hasBackdropTarget: boolean;

    declare readonly hasFirstOptionTarget: boolean;

    open(event: Event): void {
        event.preventDefault();
        this.showModal();

        const selected = this.modalTarget.querySelector<HTMLInputElement>('input[name="priority"]:checked');
        const focusTarget = selected ?? (this.hasFirstOptionTarget ? this.firstOptionTarget : null);
        if (focusTarget !== null) {
            window.requestAnimationFrame(() => focusTarget.focus());
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
