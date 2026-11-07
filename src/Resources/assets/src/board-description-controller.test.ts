import { Application } from '@hotwired/stimulus';
import { afterEach, describe, expect, it } from 'vitest';
import BoardDescriptionController from './board-description-controller';

const flush = (): Promise<void> => new Promise((resolve) => setTimeout(resolve, 0));

async function mount(scroll: number, client: number): Promise<{ text: HTMLElement; toggle: HTMLButtonElement; app: Application }> {
    const proto = HTMLElement.prototype;
    Object.defineProperty(proto, 'scrollHeight', { configurable: true, get: () => scroll });
    Object.defineProperty(proto, 'clientHeight', { configurable: true, get: () => client });
    document.body.innerHTML = `
        <div data-controller="fold" data-fold-more-value="More" data-fold-less-value="Less">
            <div id="t" data-fold-target="text">text</div>
            <button id="b" type="button" data-fold-target="toggle" data-action="fold#toggle" aria-expanded="false" hidden>More</button>
        </div>`;
    const app = Application.start();
    app.register('fold', BoardDescriptionController);
    await flush();

    return { text: document.getElementById('t') as HTMLElement, toggle: document.getElementById('b') as HTMLButtonElement, app };
}

describe('board description fold', () => {
    afterEach(() => {
        document.body.innerHTML = '';
    });

    it('folds and offers Show more when it does not fit, and toggles', async () => {
        const { text, toggle, app } = await mount(200, 60);
        expect(text.classList.contains('is-folded')).toBe(true);
        expect(toggle.hidden).toBe(false);
        toggle.click();
        expect(text.classList.contains('is-folded')).toBe(false);
        expect(toggle.getAttribute('aria-expanded')).toBe('true');
        expect(toggle.textContent).toBe('Less');
        toggle.click();
        expect(toggle.getAttribute('aria-expanded')).toBe('false');
        expect(toggle.textContent).toBe('More');
        app.stop();
    });

    it('offers nothing when it fits', async () => {
        const { toggle, app } = await mount(60, 60);
        expect(toggle.hidden).toBe(true);
        app.stop();
    });
});
