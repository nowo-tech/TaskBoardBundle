import { beforeEach, describe, expect, it, vi } from 'vitest';
import { ASK_ID, installQuestionAsk, matchesWaiting, openAsk } from './task-questions';

describe('matchesWaiting', () => {
    it('keeps every row when the filter is off', () => {
        expect(matchesWaiting(false, undefined)).toBe(true);
        expect(matchesWaiting(false, '0')).toBe(true);
    });

    it('keeps only the rows with an open question when it is on', () => {
        expect(matchesWaiting(true, '1')).toBe(true);
        expect(matchesWaiting(true, '0')).toBe(false);
        expect(matchesWaiting(true, undefined)).toBe(false);
    });
});

describe('openAsk', () => {
    beforeEach(() => {
        document.body.innerHTML = '';
        window.location.hash = '';
    });

    it('opens the disclosure and focuses its first field', () => {
        document.body.innerHTML = `<details id="${ASK_ID}"><summary>Ask</summary><input type="hidden" name="t"><textarea id="text"></textarea></details>`;
        expect(openAsk(document)).toBe(true);
        expect((document.getElementById(ASK_ID) as HTMLDetailsElement).open).toBe(true);
        expect(document.activeElement?.id).toBe('text');
    });

    it('answers false without a form', () => {
        expect(openAsk(document)).toBe(false);
    });

    it('opens without a field to focus', () => {
        document.body.innerHTML = `<details id="${ASK_ID}"><summary>Ask</summary></details>`;
        expect(openAsk(document)).toBe(true);
    });
});

describe('installQuestionAsk', () => {
    beforeEach(() => {
        document.body.innerHTML = '';
        window.location.hash = '';
    });

    it('opens the form named by the hash', () => {
        document.body.innerHTML = `<details id="${ASK_ID}"><summary>Ask</summary><textarea></textarea></details>`;
        window.location.hash = `#${ASK_ID}`;
        installQuestionAsk(document);
        expect((document.getElementById(ASK_ID) as HTMLDetailsElement).open).toBe(true);
    });

    it('opens the form when a link to it is clicked', () => {
        document.body.innerHTML = `<a id="link" href="#${ASK_ID}">Ask</a><a id="other" href="#elsewhere">Other</a><span id="plain">x</span><details id="${ASK_ID}"><summary>Ask</summary><textarea></textarea></details>`;
        const details = document.getElementById(ASK_ID) as HTMLDetailsElement;
        details.scrollIntoView = vi.fn();
        installQuestionAsk(document);
        document.getElementById('other')?.click();
        document.getElementById('plain')?.click();
        expect(details.open).toBe(false);
        const event = new MouseEvent('click', { bubbles: true, cancelable: true });
        document.getElementById('link')?.dispatchEvent(event);
        expect(details.open).toBe(true);
        expect(event.defaultPrevented).toBe(true);
        expect(details.scrollIntoView).toHaveBeenCalled();
    });

    it('lets the link go when the page has no form', () => {
        document.body.innerHTML = `<a id="link" href="#${ASK_ID}">Ask</a>`;
        installQuestionAsk(document);
        const event = new MouseEvent('click', { bubbles: true, cancelable: true });
        document.getElementById('link')?.dispatchEvent(event);
        expect(event.defaultPrevented).toBe(false);
    });

    it('ignores a click on the document itself', () => {
        installQuestionAsk(document);
        document.dispatchEvent(new MouseEvent('click', { bubbles: true }));
        expect(document.getElementById(ASK_ID)).toBeNull();
    });
});
