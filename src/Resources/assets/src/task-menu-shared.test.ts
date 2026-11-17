import { beforeEach, describe, expect, it } from 'vitest';
import { fillSharedMenus, installSharedMenus } from './task-menu-shared';

const TEMPLATE = `<template id="nowo-task-menu-shared">
<li><details data-nowo-task-menu="status"><summary>Status</summary><ul>
<li><button value="__TASK__|status:c1" data-nowo-column="c1">To do</button></li>
<li><button value="__TASK__|status:c2" data-nowo-column="c2">Doing</button></li></ul></details></li>
<li><details data-nowo-task-menu="priority"><summary>Priority</summary><ul>
<li><button value="__TASK__|priority:low" data-nowo-priority="low">Low</button></li>
<li><button value="__TASK__|priority:normal" data-nowo-priority="normal">Normal</button></li></ul></details></li>
<li><button value="other">Other</button></li>
<button value="__TASK__|priority:high" data-nowo-priority="high">High</button>
</template>`;

function card(id: string, column: string | null = 'c1', priority: string | null = 'normal'): string {
    const attrs = (column !== null ? ` data-column="${column}"` : '') + (priority !== null ? ` data-priority="${priority}"` : '');
    return `<li class="card" data-nowo-ui-context-menu-scope tabindex="0"><span class="title">T</span><details class="nowo-task-menu"><summary>⋮</summary><ul>
        <li hidden data-nowo-task-menu-shared="${id}"${attrs}></li></ul></details></li>`;
}

describe('shared task menus (2.22.0)', () => {
    beforeEach(() => {
        document.body.innerHTML = `${TEMPLATE}<ul>${card('t1')}${card('t2', null, null)}</ul>`;
    });

    it('fills a slot with the task id, without its own column and priority', () => {
        const first = document.querySelector('.card')!;
        expect(fillSharedMenus(first, document)).toBe(1);
        expect([...first.querySelectorAll('button')].map((b) => b.getAttribute('value'))).toEqual(['t1|status:c2', 't1|priority:low', 'other', 't1|priority:high']);
        expect(first.querySelector('[data-nowo-task-menu-shared]')).toBeNull();
        expect(fillSharedMenus(first, document)).toBe(0);
        const second = document.querySelectorAll('.card')[1];
        fillSharedMenus(second, document);
        expect(second.querySelectorAll('button').length).toBe(6);
    });

    it('fills the card a pointer, key or focus reaches, before the context menu reads it', () => {
        installSharedMenus(document);
        const [first, second] = [...document.querySelectorAll<HTMLElement>('.card')];
        first.querySelector('.title')!.dispatchEvent(new Event('contextmenu', { bubbles: true }));
        expect(first.querySelectorAll('button').length).toBe(4);
        expect(second.querySelector('[data-nowo-task-menu-shared]')).not.toBeNull();
        second.dispatchEvent(new Event('focusin', { bubbles: true }));
        expect(second.querySelector('[data-nowo-task-menu-shared]')).toBeNull();
        document.body.dispatchEvent(new Event('pointerdown', { bubbles: true }));
    });

    it('fills a kebab outside a card scope, and a slot without an id', () => {
        document.body.innerHTML = `${TEMPLATE}<div><details class="nowo-task-menu"><summary>⋮</summary><ul><li hidden data-nowo-task-menu-shared data-priority="high"></li></ul></details></div>`;
        installSharedMenus(document);
        document.querySelector('summary')!.dispatchEvent(new Event('pointerover', { bubbles: true }));
        expect([...document.querySelectorAll('button')].map((b) => b.getAttribute('value'))).toEqual(['|status:c1', '|status:c2', '|priority:low', '|priority:normal', 'other', '|priority:high']);
    });

    it('does nothing without the template', () => {
        document.body.innerHTML = card('t3');
        expect(fillSharedMenus(document, document)).toBe(0);
        expect(document.querySelector('[data-nowo-task-menu-shared]')).not.toBeNull();
    });
});
