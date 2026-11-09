import { describe, expect, it } from 'vitest';
import { showDeleteWarning } from './edit-column-modal-controller';

function mount(): { form: HTMLFormElement; note: HTMLElement } {
    document.body.innerHTML = `
        <p id="note" hidden></p>
        <form id="f" data-confirm="Delete this column?"></form>`;

    return { form: document.getElementById('f') as HTMLFormElement, note: document.getElementById('note') as HTMLElement };
}

describe('the host warnings of "Delete column" (spec 216, FR-010)', () => {
    it('shows the warning as text and adds it to the confirmation', () => {
        const { form, note } = mount();
        showDeleteWarning(form, note, '2 automation rules use this column; they will pause.\n<b>x</b>');

        expect(note.hidden).toBe(false);
        expect(note.textContent).toBe('2 automation rules use this column; they will pause.\n<b>x</b>');
        expect(note.querySelector('b')).toBeNull();
        expect(form.getAttribute('data-confirm')).toBe('Delete this column?\n\n2 automation rules use this column; they will pause.\n<b>x</b>');
    });

    it('goes back to the bundle text for a column without warnings', () => {
        const { form, note } = mount();
        showDeleteWarning(form, note, 'One rule uses this column.');
        showDeleteWarning(form, note, '  ');

        expect(note.hidden).toBe(true);
        expect(note.textContent).toBe('');
        expect(form.getAttribute('data-confirm')).toBe('Delete this column?');
    });

    it('works without a note element', () => {
        const { form } = mount();
        form.removeAttribute('data-confirm');
        showDeleteWarning(form, null, 'Careful.');

        expect(form.getAttribute('data-confirm')).toBe('Careful.');
    });
});
