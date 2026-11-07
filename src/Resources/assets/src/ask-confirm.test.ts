import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { askConfirm, installFormConfirm } from './ask-confirm';

const tick = (): Promise<void> => new Promise((resolve) => setTimeout(resolve, 0));

describe('askConfirm', () => {
    afterEach(() => {
        delete window.nowoUiAskConfirm;
        vi.restoreAllMocks();
    });

    it('asks through the kit', async () => {
        window.nowoUiAskConfirm = vi.fn().mockResolvedValue(true);
        await expect(askConfirm('Sure?', false)).resolves.toBe(true);
        expect(window.nowoUiAskConfirm).toHaveBeenCalledWith('Sure?', { danger: false });
    });

    it('falls back to the browser when the kit script is missing', async () => {
        vi.spyOn(window, 'confirm').mockReturnValue(false);
        await expect(askConfirm('Sure?')).resolves.toBe(false);
    });
});

describe('installFormConfirm', () => {
    let submitted: string[];

    beforeEach(() => {
        submitted = [];
        document.body.innerHTML = `
            <form id="plain"><button id="plain-go" type="submit">Go</button></form>
            <form id="asks" data-confirm="Delete?"><button id="asks-go" type="submit">Go</button></form>
            <form id="shared"><button id="first" type="submit" data-confirm="One?" formaction="/one">1</button></form>`;
        document.addEventListener('submit', record);
    });

    afterEach(() => {
        document.removeEventListener('submit', record);
        delete window.nowoUiAskConfirm;
    });

    function record(event: Event): void {
        if (!event.defaultPrevented) {
            submitted.push((event.target as HTMLFormElement).id);
        }
        event.preventDefault();
    }

    installFormConfirm(document);

    it('leaves a form without a question alone', () => {
        document.getElementById('plain-go')?.click();
        expect(submitted).toEqual(['plain']);
    });

    it('submits only after a yes', async () => {
        window.nowoUiAskConfirm = vi.fn().mockResolvedValueOnce(false).mockResolvedValueOnce(true);
        document.getElementById('asks-go')?.click();
        await tick();
        expect(submitted).toEqual([]);
        document.getElementById('asks-go')?.click();
        await tick();
        expect(submitted).toEqual(['asks']);
    });

    it('reads the question from the submit button', async () => {
        window.nowoUiAskConfirm = vi.fn().mockResolvedValue(true);
        document.getElementById('first')?.click();
        await tick();
        expect(window.nowoUiAskConfirm).toHaveBeenCalledWith('One?', { danger: true });
        expect(submitted).toEqual(['shared']);
    });
});
