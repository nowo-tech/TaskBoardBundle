import { describe, expect, it } from 'vitest';
import { applyCssVars, applyCssVarsIn, observeCssVars } from './css-vars';

describe('applyCssVars', () => {
    it('sets custom properties from data-nowo-task-vars', () => {
        const el = document.createElement('div');
        el.setAttribute('data-nowo-task-vars', '{"--nowo-task-column-color":"#16a34a","--gantt-start":3}');
        applyCssVars(el);

        expect(el.style.getPropertyValue('--nowo-task-column-color')).toBe('#16a34a');
        expect(el.style.getPropertyValue('--gantt-start')).toBe('3');
    });

    it('ignores regular properties, odd values and broken JSON', () => {
        const el = document.createElement('div');
        el.setAttribute('data-nowo-task-vars', '{"color":"red","--x":{"a":1},"--y":true}');
        applyCssVars(el);
        expect(el.getAttribute('style')).toBeNull();

        for (const raw of ['', 'not json', '[1]', 'null']) {
            const other = document.createElement('div');
            other.setAttribute('data-nowo-task-vars', raw);
            applyCssVars(other);
            expect(other.getAttribute('style')).toBeNull();
        }

        const none = document.createElement('div');
        applyCssVars(none);
        expect(none.getAttribute('style')).toBeNull();
    });

    it('applies the root and its descendants', () => {
        const root = document.createElement('section');
        root.setAttribute('data-nowo-task-vars', '{"--a":"1"}');
        root.innerHTML = '<span data-nowo-task-vars=\'{"--b":"2"}\'></span>';
        applyCssVarsIn(root);

        expect(root.style.getPropertyValue('--a')).toBe('1');
        expect((root.firstElementChild as HTMLElement).style.getPropertyValue('--b')).toBe('2');
    });

    it('applies the variables of elements added later', async () => {
        const root = document.createElement('div');
        document.body.appendChild(root);
        const observer = observeCssVars(root);

        const panel = document.createElement('div');
        panel.innerHTML = '<i data-nowo-task-vars=\'{"--c":"3"}\'></i>';
        root.appendChild(panel);
        root.appendChild(document.createTextNode('text'));
        await Promise.resolve();

        expect((panel.firstElementChild as HTMLElement).style.getPropertyValue('--c')).toBe('3');
        observer.disconnect();
    });
});
