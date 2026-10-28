/**
 * Since 1.14: opens and closes the dialog of a modal controller (add task, people, priority, links). The bundle's
 * templates render a native <dialog class="nowo-task-dialog nowo-task-dialog--native"> shown with showModal() (top
 * layer, Escape and the focus trap from the browser; a click on the backdrop closes it). Template overrides that kept
 * the Bootstrap `.modal` markup (with its `.modal-backdrop` target) keep working through the legacy branch.
 */
export class ModalToggle {
    constructor(
        private readonly modal: HTMLElement,
        private readonly backdrop: HTMLElement | null,
        private readonly onClose: () => void,
    ) {}

    show(): void {
        if (this.modal instanceof HTMLDialogElement) {
            if (!this.modal.open) {
                this.modal.addEventListener('click', this.closeOnDialogBackdrop);
                this.modal.showModal();
            }

            return;
        }
        this.modal.classList.add('show');
        this.modal.style.display = 'block';
        this.modal.removeAttribute('aria-hidden');
        this.modal.setAttribute('aria-modal', 'true');
        document.body.classList.add('modal-open');
        this.backdrop?.classList.add('show');
        document.addEventListener('keydown', this.closeOnEscape);
    }

    hide(): void {
        if (this.modal instanceof HTMLDialogElement) {
            this.modal.removeEventListener('click', this.closeOnDialogBackdrop);
            this.modal.close();

            return;
        }
        this.modal.classList.remove('show');
        this.modal.style.display = 'none';
        this.modal.setAttribute('aria-hidden', 'true');
        this.modal.removeAttribute('aria-modal');
        document.body.classList.remove('modal-open');
        this.backdrop?.classList.remove('show');
        document.removeEventListener('keydown', this.closeOnEscape);
    }

    /** Legacy `.modal` markup: a click on its `.modal-backdrop` element closes it. */
    isBackdrop(target: EventTarget | null): boolean {
        return this.backdrop !== null && target === this.backdrop;
    }

    private closeOnDialogBackdrop = (event: MouseEvent): void => {
        if (event.target === this.modal) {
            this.onClose();
        }
    };

    private closeOnEscape = (event: KeyboardEvent): void => {
        if (event.key === 'Escape') {
            this.onClose();
        }
    };
}
