import { Application } from '@hotwired/stimulus';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import BoardCreateStepsController from './board-create-steps-controller';

const flush = (): Promise<void> => new Promise((resolve) => setTimeout(resolve, 0));

async function mount(start: number, checked: string | null = null, scopes: string[] = ['personal', 'org']): Promise<{ form: HTMLFormElement; back: HTMLButtonElement; submit: HTMLButtonElement; app: Application }> {
    const radios = scopes.map((id) => `<input type="radio" name="s" value="${id}"${checked === id ? ' checked' : ''}>`).join('');
    document.body.innerHTML = `
        <form id="f" data-controller="steps" data-steps-start-value="${start}">
            <div data-steps-target="scopes">${radios}<button type="button" id="next" data-action="steps#next">Next</button></div>
            <div data-steps-target="details">
                <div data-steps-target="scopeFields" data-scope="personal" id="p"></div>
                <div data-steps-target="scopeFields" data-scope="org" id="o"></div>
            </div>
        </form>
        <footer>
            <button type="button" id="back" data-nowo-ui-dialog-back data-nowo-task-board-create-form="f"><span id="inner">Back</span></button>
            <button type="button" id="other-back" data-nowo-ui-dialog-back data-nowo-task-board-create-form="g">Other</button>
            <button type="submit" id="go" form="f">Create</button>
            <button type="button" id="plain" form="f">Cancel</button>
        </footer>`;
    const app = Application.start();
    app.register('steps', BoardCreateStepsController);
    await flush();

    return {
        form: document.getElementById('f') as HTMLFormElement,
        back: document.getElementById('back') as HTMLButtonElement,
        submit: document.getElementById('go') as HTMLButtonElement,
        app,
    };
}

const el = (id: string): HTMLElement => document.getElementById(id) as HTMLElement;
const scopes = (): HTMLElement => document.querySelector('[data-steps-target="scopes"]') as HTMLElement;
const details = (): HTMLElement => document.querySelector('[data-steps-target="details"]') as HTMLElement;

describe('board create steps', () => {
    let app: Application | undefined;

    beforeEach(() => {
        app = undefined;
    });

    afterEach(() => {
        app?.stop();
        document.body.innerHTML = '';
    });

    it('starts on the scopes, with Create and Back hidden', async () => {
        const ctx = await mount(1);
        app = ctx.app;
        expect(scopes().hidden).toBe(false);
        expect(details().hidden).toBe(true);
        expect(ctx.submit.hidden).toBe(true);
        expect(ctx.back.hidden).toBe(true);
        expect(el('plain').hidden).toBe(false);
        expect(el('other-back').hidden).toBe(false);
    });

    it('stays on the scopes until one is chosen', async () => {
        const ctx = await mount(1);
        app = ctx.app;
        el('next').click();
        expect(details().hidden).toBe(true);
    });

    it('opens the chosen scope with its fields and says so', async () => {
        const ctx = await mount(1);
        app = ctx.app;
        const shown = vi.fn();
        ctx.form.addEventListener('steps:step-shown', shown);
        (document.querySelector('input[value="org"]') as HTMLInputElement).checked = true;
        el('next').click();
        expect(scopes().hidden).toBe(true);
        expect(details().hidden).toBe(false);
        expect(el('o').hidden).toBe(false);
        expect(el('p').hidden).toBe(true);
        expect(ctx.submit.hidden).toBe(false);
        expect(ctx.back.hidden).toBe(false);
        expect((shown.mock.calls[0][0] as CustomEvent).detail).toEqual({ scope: 'org' });
    });

    it('goes back from a click inside the lifted button, and ignores another form and other clicks', async () => {
        const ctx = await mount(2, 'org');
        app = ctx.app;
        el('other-back').click();
        expect(details().hidden).toBe(false);
        el('go').click();
        expect(details().hidden).toBe(false);
        document.dispatchEvent(new Event('click'));
        expect(details().hidden).toBe(false);
        el('inner').click();
        expect(scopes().hidden).toBe(false);
        expect(details().hidden).toBe(true);
        expect(ctx.back.hidden).toBe(true);
    });

    it('opens on step 2 when one is chosen, not when none is', async () => {
        let ctx = await mount(2, 'personal');
        expect(details().hidden).toBe(false);
        ctx.app.stop();
        ctx = await mount(2);
        app = ctx.app;
        expect(details().hidden).toBe(true);
    });

    it('has no Back with one scope', async () => {
        const ctx = await mount(2, 'org', ['org']);
        app = ctx.app;
        expect(details().hidden).toBe(false);
        expect(ctx.back.hidden).toBe(true);
    });

    it('stops listening when it leaves', async () => {
        const ctx = await mount(2, 'org');
        app = ctx.app;
        const form = details();
        ctx.form.remove();
        await flush();
        ctx.back.click();
        expect(form.hidden).toBe(false);
    });
});
