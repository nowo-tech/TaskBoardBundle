/**
 * Since 2.23.0 (Beacon spec 242): the client side of questions on tasks.
 *
 * - `#nowo-task-question-ask` (the task menu's "Ask a question…", the comment box's link, `?ask=1`) opens the ask
 *   disclosure and focuses its first field, also inside a drawer loaded later;
 * - the list filter's "Waiting for answer" ({@link matchesWaiting}) keeps the rows with an open question
 *   (`data-waiting="1"`).
 */
export const ASK_ID = 'nowo-task-question-ask';

/** Whether a row passes the "Waiting for answer" filter. */
export function matchesWaiting(waitingOnly: boolean, waiting: string | undefined): boolean {
    return !waitingOnly || waiting === '1';
}

/** Opens the ask disclosure under `root` and focuses its first field; returns whether there was one. */
export function openAsk(root: ParentNode): boolean {
    const details = root.querySelector<HTMLDetailsElement>(`#${ASK_ID}`);
    if (details === null) {
        return false;
    }
    details.open = true;
    details.querySelector<HTMLElement>('textarea, input:not([type="hidden"])')?.focus();

    return true;
}

/**
 * Opens the ask form when the page's hash names it, and when a link to it is followed (a click on
 * `a[href$="#nowo-task-question-ask"]` that stays on the page).
 */
export function installQuestionAsk(doc: Document): void {
    if (doc.location?.hash === `#${ASK_ID}`) {
        openAsk(doc);
    }
    doc.addEventListener('click', (event) => {
        const link = (event.target as Element | null)?.closest?.('a[href]');
        if (link === null || link === undefined || link.getAttribute('href') !== `#${ASK_ID}`) {
            return;
        }
        if (openAsk(doc)) {
            event.preventDefault();
            doc.getElementById(ASK_ID)?.scrollIntoView({ block: 'start' });
        }
    });
}
