import { describe, expect, it } from 'vitest';
import { isTypingTarget, stepOf } from './task-nav-utils';

function key(init: Partial<KeyboardEvent> & { key: string }, target: EventTarget | null = document.body) {
    return { altKey: false, ctrlKey: false, metaKey: false, shiftKey: false, target, ...init };
}

describe('task-nav-utils', () => {
    it('knows the fields where letters are text', () => {
        document.body.innerHTML = '<input id="i"><textarea id="t"></textarea><select id="s"></select><div contenteditable="true"><p id="p"></p></div><div role="textbox" id="r"></div><button id="b"></button>';
        for (const id of ['i', 't', 's', 'p', 'r']) {
            expect(isTypingTarget(document.getElementById(id))).toBe(true);
        }
        expect(isTypingTarget(document.getElementById('b'))).toBe(false);
        expect(isTypingTarget(null)).toBe(false);
        expect(isTypingTarget(window)).toBe(false);
        expect(isTypingTarget(document.getElementById('i'), true)).toBe(false);
        expect(isTypingTarget(document.getElementById('t'), true)).toBe(true);
    });

    it('maps Alt+arrows anywhere and J / K outside fields', () => {
        document.body.innerHTML = '<input id="i"><textarea id="t"></textarea>';
        const input = document.getElementById('i');
        expect(stepOf(key({ key: 'ArrowLeft', altKey: true }, document.getElementById('t')))).toBeNull();
        expect(stepOf(key({ key: 'ArrowLeft', altKey: true }, input))).toBe('previous');
        expect(stepOf(key({ key: 'ArrowRight', altKey: true }, input))).toBe('next');
        expect(stepOf(key({ key: 'ArrowUp', altKey: true }))).toBeNull();
        expect(stepOf(key({ key: 'k' }))).toBe('previous');
        expect(stepOf(key({ key: 'J' }))).toBe('next');
        expect(stepOf(key({ key: 'x' }))).toBeNull();
        expect(stepOf(key({ key: 'j' }, input))).toBeNull();
        expect(stepOf(key({ key: 'j', ctrlKey: true }))).toBeNull();
        expect(stepOf(key({ key: 'j', metaKey: true }))).toBeNull();
        expect(stepOf(key({ key: 'ArrowLeft', altKey: true, shiftKey: true }))).toBeNull();
    });
});
