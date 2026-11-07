import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { installBoardOpeners } from './board-openers';

describe('installBoardOpeners', () => {
    installBoardOpeners(document);
    let open: ReturnType<typeof vi.fn>;

    beforeEach(() => {
        open = vi.fn();
        window.nowoUiOpenDialog = open;
        document.body.innerHTML = `
            <dialog id="nowo-task-column-set-save"></dialog>
            <button id="save" data-nowo-task-board-open="column-set-save"><span id="inner">Save</span></button>
            <button id="other" data-nowo-task-board-open="constructor">Other</button>
            <button id="plain">Plain</button>`;
    });

    afterEach(() => {
        delete window.nowoUiOpenDialog;
    });

    it('opens the named dialog from the button or its content', () => {
        document.getElementById('inner')?.click();
        expect(open).toHaveBeenCalledOnce();
        expect(open.mock.calls[0][0]).toBe(document.getElementById('nowo-task-column-set-save'));
    });

    it('ignores unknown names and other elements', () => {
        document.getElementById('other')?.click();
        document.getElementById('plain')?.click();
        expect(open).not.toHaveBeenCalled();
    });
});
