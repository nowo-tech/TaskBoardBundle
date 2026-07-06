import { Controller } from '@hotwired/stimulus';

/**
 * Manage assignees modal on the task detail page.
 */
export default class TaskMembersModalController extends Controller<HTMLElement> {
    static targets = ['modal', 'backdrop', 'userField'];

    declare readonly modalTarget: HTMLElement;

    declare readonly backdropTarget: HTMLElement;

    declare readonly userFieldTarget: HTMLSelectElement;

    declare readonly hasBackdropTarget: boolean;

    declare readonly hasUserFieldTarget: boolean;

    open(event: Event): void {
        event.preventDefault();
        this.showModal();

        if (this.hasUserFieldTarget) {
            window.requestAnimationFrame(() => this.userFieldTarget.focus());
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
