import { describe, expect, it } from 'vitest';
import { dialogIcon, showCurrentIcon } from './edit-column-modal-controller';

function mount(): { select: HTMLSelectElement; grid: HTMLElement } {
    document.body.innerHTML = `
        <form id="f"><div class="nowo-task-picker">
            <div hidden><select id="icon" name="icon"><option value="">No icon</option><option value="tabler:bug">bug</option></select></div>
            <div class="nowo-task-picker__icons"><button type="button" data-value="tabler:bug">bug</button></div>
        </div></form>`;

    return { select: document.getElementById('icon') as HTMLSelectElement, grid: document.querySelector('.nowo-task-picker__icons') as HTMLElement };
}

describe('the current column icon in the grid (JM-L1)', () => {
    it('adds a selected option and a grid button for an icon the grid does not offer, as text', () => {
        const { select, grid } = mount();
        showCurrentIcon(select, '<b>📝</b>');

        expect(select.value).toBe('<b>📝</b>');
        expect(new FormData(document.getElementById('f') as HTMLFormElement).get('icon')).toBe('<b>📝</b>');
        const added = grid.querySelector<HTMLButtonElement>('[data-nowo-task-current-icon]');
        expect(added?.dataset.value).toBe('<b>📝</b>');
        expect(added?.getAttribute('aria-pressed')).toBe('true');
        expect(added?.querySelector('b')).toBeNull();
        expect(added?.textContent).toContain('<b>📝</b>');
    });

    it('removes them again for an offered icon or no icon', () => {
        const { select, grid } = mount();
        showCurrentIcon(select, '📝');
        showCurrentIcon(select, '✅');
        expect(select.querySelectorAll('[data-nowo-task-current-icon]')).toHaveLength(1);
        expect(grid.querySelectorAll('[data-nowo-task-current-icon]')).toHaveLength(1);

        showCurrentIcon(select, 'tabler:bug');
        expect(select.value).toBe('tabler:bug');
        expect(document.querySelectorAll('[data-nowo-task-current-icon]')).toHaveLength(0);

        showCurrentIcon(select, '');
        expect(select.value).toBe('');
    });

    it('works without a grid', () => {
        document.body.innerHTML = '<select id="icon"><option value="">No icon</option></select>';
        const select = document.getElementById('icon') as HTMLSelectElement;
        showCurrentIcon(select, '📝');
        expect(select.value).toBe('📝');
    });
});

describe('the icon the edit dialog opens with (JM-I7)', () => {
    function trigger(attributes: string): HTMLElement {
        document.body.innerHTML = `<button type="button" data-column-id="7" ${attributes}></button>`;

        return document.querySelector('button') as HTMLElement;
    }

    it('is the stored icon', () => {
        expect(dialogIcon(trigger('data-column-icon="📝"'))).toBe('📝');
        expect(dialogIcon(trigger('data-column-icon="tabler:bug"'))).toBe('tabler:bug');
        expect(dialogIcon(trigger('data-column-icon=""'))).toBe('');
        expect(dialogIcon(trigger(''))).toBe('');
    });

    it('is "No icon" for a stored icon the server would not store, so the grid adds no option for it', () => {
        const button = trigger('data-column-icon="fa fa-bug" data-column-icon-storable="0"');
        expect(dialogIcon(button)).toBe('');

        const { select } = mount();
        showCurrentIcon(select, dialogIcon(button));
        expect(select.value).toBe('');
        expect(document.querySelectorAll('[data-nowo-task-current-icon]')).toHaveLength(0);
    });
});
