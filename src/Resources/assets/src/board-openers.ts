import { openKitDialog } from './dialog-utils';

const DIALOGS: Record<string, string> = {
    'column-set-save': 'nowo-task-column-set-save',
    'column-set-apply': 'nowo-task-column-set-apply',
};

/**
 * Since 2.0.0 (TB-10): `data-nowo-task-board-open="column-set-save|column-set-apply"` on any element opens that dialog, so a
 * host with `board.toolbar: false` draws its own buttons. The bundle's own buttons also carry UiKit's opener, which got there first.
 */
export function installBoardOpeners(root: Document): void {
    root.addEventListener('click', (event: Event) => {
        const opener = event.target instanceof Element ? event.target.closest('[data-nowo-task-board-open]') : null;
        const id = opener?.getAttribute('data-nowo-task-board-open') ?? '';
        const dialog = Object.prototype.hasOwnProperty.call(DIALOGS, id) ? root.getElementById(DIALOGS[id]) : null;
        if (opener !== null && dialog instanceof HTMLDialogElement && !dialog.open) {
            openKitDialog(dialog, opener);
        }
    });
}
