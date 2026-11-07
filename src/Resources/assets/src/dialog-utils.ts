/**
 * Since 2.0.0: opens a kit `dialog` that a controller filled first. UiKit's dialog runtime (js/nowo-ui-dialog.js, in the kit's
 * default scripts) takes the overlay registry, the focus return, the close buttons and the backdrop.
 */
export function openKitDialog(dialog: HTMLDialogElement, opener: Element | null, focus: HTMLElement | null = null): void {
    if (window.nowoUiOpenDialog !== undefined) {
        window.nowoUiOpenDialog(dialog, opener);
    } else if (!dialog.open) {
        dialog.showModal();
    }
    if (focus !== null) {
        window.requestAnimationFrame(() => focus.focus());
    }
}

declare global {
    interface Window {
        nowoUiOpenDialog?: (id: string | HTMLDialogElement | null, opener?: Element | null) => void;
        nowoUiAskConfirm?: (message: string, options?: { danger?: boolean }) => Promise<boolean>;
    }
}
