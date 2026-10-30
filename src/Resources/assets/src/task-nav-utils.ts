/**
 * Since 1.20: the keyboard steps of a task page (task-nav-controller) — Alt+← / Alt+→ (outside a multi-line text,
 * where they move the caret by word), J (next) / K (previous) while the focus is not in a field.
 */
export type StepDirection = 'previous' | 'next';

/** Multi-line text, where Alt+arrows move the caret by word. */
const MULTILINE = 'textarea, [contenteditable=""], [contenteditable="true"], [role="textbox"]';

/** Fields where J / K are typed text, not a step (inputs, text areas, selects, rich editors). */
export function isTypingTarget(target: EventTarget | null, multilineOnly = false): boolean {
    if (!(target instanceof Element)) {
        return false;
    }

    return null !== target.closest(multilineOnly ? MULTILINE : `input, select, ${MULTILINE}`);
}

/** The step a key asks for, or null (other keys, Ctrl / Meta / Shift chords, keys that are text in the focused field). */
export function stepOf(event: Pick<KeyboardEvent, 'key' | 'altKey' | 'ctrlKey' | 'metaKey' | 'shiftKey' | 'target'>): StepDirection | null {
    if (event.ctrlKey || event.metaKey || event.shiftKey || isTypingTarget(event.target, event.altKey)) {
        return null;
    }
    if (event.altKey) {
        return 'ArrowLeft' === event.key ? 'previous' : 'ArrowRight' === event.key ? 'next' : null;
    }
    const key = event.key.toLowerCase();

    return 'k' === key ? 'previous' : 'j' === key ? 'next' : null;
}
