import { Controller } from '@hotwired/stimulus';

/**
 * Native <dialog> elements (since 1.11, column sets): open(event) shows the dialog whose data-dialog matches the
 * button's data-dialog (or the first one) with showModal() — focus trap, Escape and the top layer come from the
 * browser — and returns the focus to the button when it closes; confirm(event) asks before a destructive submit.
 */
export default class DialogController extends Controller<HTMLElement> {
    static targets = ['dialog'];

    declare readonly dialogTargets: HTMLDialogElement[];

    private opener: HTMLElement | null = null;

    open(event: Event): void {
        const trigger = event.currentTarget as HTMLElement;
        const name = trigger.dataset.dialog ?? '';
        const dialog = this.dialogTargets.find((candidate) => name === '' || candidate.dataset.dialog === name) ?? this.dialogTargets[0];
        if (dialog === undefined) {
            return;
        }

        this.opener = trigger;
        dialog.addEventListener('close', this.restoreFocus, { once: true });
        dialog.addEventListener('click', this.closeOnBackdrop);
        dialog.showModal();
    }

    close(event: Event): void {
        (event.currentTarget as HTMLElement).closest('dialog')?.close();
    }

    confirm(event: Event): void {
        const form = event.currentTarget as HTMLElement;
        const message = form.dataset.confirm ?? '';
        if (message !== '' && !window.confirm(message)) {
            event.preventDefault();
        }
    }

    private closeOnBackdrop = (event: MouseEvent): void => {
        const dialog = event.currentTarget as HTMLDialogElement;
        if (event.target === dialog) {
            dialog.close();
        }
    };

    private restoreFocus = (): void => {
        this.opener?.focus();
        this.opener = null;
    };
}
