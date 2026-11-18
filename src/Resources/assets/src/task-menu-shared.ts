/**
 * Since 2.22.0: the Status, Priority and Due date submenus of the task menus are printed once per page, in
 * `<template id="nowo-task-menu-shared">`, and each card or row menu holds a hidden slot
 * (`data-nowo-task-menu-shared="<task id>"`, `data-column`, `data-priority`). Before a menu or its context menu can be read
 * (pointer, touch, key or focus on the card or row, in the capture phase, so before UiKit's context menu reads the kebab),
 * the slot is replaced by a copy of the lists with the task's id in each entry's `action` and without the task's own column
 * and priority. The server checks every write again; this only spares the page a copy of the lists per card.
 */
export const TEMPLATE_ID = 'nowo-task-menu-shared';

const PLACEHOLDER = '__TASK__|';

const SCOPES = '[data-nowo-ui-context-menu-scope], tr, .nowo-task-menu';

/** Fills the slots under `scope`; returns how many were filled. */
export function fillSharedMenus(scope: ParentNode, doc: Document): number {
    const template = doc.getElementById(TEMPLATE_ID);
    if (!(template instanceof HTMLTemplateElement)) {
        return 0;
    }
    let filled = 0;
    scope.querySelectorAll<HTMLElement>('[data-nowo-task-menu-shared]').forEach((slot) => {
        const taskId = slot.getAttribute('data-nowo-task-menu-shared') as string;
        const column = slot.getAttribute('data-column') ?? '';
        const priority = slot.getAttribute('data-priority') ?? '';
        const copy = template.content.cloneNode(true) as DocumentFragment;
        copy.querySelectorAll('button[value]').forEach((button) => {
            const value = button.getAttribute('value') as string;
            if (value.startsWith(PLACEHOLDER)) {
                button.setAttribute('value', `${taskId}|${value.slice(PLACEHOLDER.length)}`);
            }
            const own = (column !== '' && button.getAttribute('data-nowo-column') === column) || (priority !== '' && button.getAttribute('data-nowo-priority') === priority);
            if (own) {
                button.closest('li')?.remove();
            }
        });
        slot.replaceWith(copy);
        filled += 1;
    });

    return filled;
}

/** One delegated listener per event type, in the capture phase. */
export function installSharedMenus(root: Document): void {
    const fill = (event: Event): void => {
        const scope = event.target instanceof Element ? event.target.closest(SCOPES) : null;
        if (scope !== null) {
            fillSharedMenus(scope.closest('[data-nowo-ui-context-menu-scope], tr') ?? scope, root);
        }
    };
    for (const type of ['pointerdown', 'pointerover', 'contextmenu', 'keydown', 'focusin']) {
        root.addEventListener(type, fill, true);
    }
}
