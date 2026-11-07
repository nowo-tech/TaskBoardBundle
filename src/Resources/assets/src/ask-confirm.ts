/**
 * Since 2.0.0: the kit's runtime question, for actions whose target or message is only known here. The browser's own
 * question is left for a host layout that dropped UiKit's `confirm` script: the action must not go unasked.
 */
export function askConfirm(message: string, danger = true): Promise<boolean> {
    return window.nowoUiAskConfirm !== undefined ? window.nowoUiAskConfirm(message, { danger }) : Promise.resolve(window.confirm(message));
}

const asked = new WeakSet<HTMLFormElement>();

/**
 * A form or submit button with `data-confirm` asks before it submits. One delegated listener, so it also works in fragments
 * loaded later (the task panel), and the button that was used, with its `formaction`, is the one submitted.
 */
export function installFormConfirm(root: Document): void {
    root.addEventListener('submit', (event: SubmitEvent) => {
        const form = event.target;
        if (!(form instanceof HTMLFormElement)) {
            return;
        }
        if (asked.has(form)) {
            asked.delete(form);

            return;
        }
        const submitter = event.submitter;
        const message = submitter?.getAttribute('data-confirm') ?? form.getAttribute('data-confirm') ?? '';
        if (message === '') {
            return;
        }
        event.preventDefault();
        void askConfirm(message).then((confirmed) => {
            if (confirmed) {
                asked.add(form);
                form.requestSubmit(submitter instanceof HTMLButtonElement ? submitter : undefined);
            }
        });
    });
}
