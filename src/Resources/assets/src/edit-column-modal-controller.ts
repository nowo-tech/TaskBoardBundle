import { Controller } from '@hotwired/stimulus';

/** Marks the option and the grid button {@link showCurrentIcon} adds, so the next opening removes them. */
const CURRENT = 'data-nowo-task-current-icon';

/**
 * Since 2.9.0 (audit JM-L1): the grid's hidden <select> only knows `board.column_icons`. A column icon it does not offer (an emoji
 * from `board.default_columns`, the fill command or the API) gets its own selected option, so it posts back and the edit keeps
 * it, and a button in the grid that shows it (as text) as the current choice. The server accepts that value for that column.
 */
export function showCurrentIcon(select: HTMLSelectElement, icon: string): void {
    const field = select.closest('.nowo-task-picker');
    field?.querySelectorAll(`[${CURRENT}]`).forEach((node) => node.remove());
    if (icon === '' || [...select.options].some((option) => option.value === icon)) {
        select.value = icon;

        return;
    }

    const option = new Option(icon, icon, true, true);
    option.setAttribute(CURRENT, '');
    select.add(option);
    select.value = icon;

    const grid = field?.querySelector('.nowo-task-picker__icons');
    if (grid) {
        const button = document.createElement('button');
        button.type = 'button';
        button.className = 'nowo-task-picker__icon nowo-task-picker__icon--current';
        button.dataset.value = icon;
        button.dataset.action = 'nowo-task-board-picker#pick';
        button.setAttribute('data-nowo-task-board-picker-target', 'option');
        button.setAttribute('aria-pressed', 'true');
        button.setAttribute(CURRENT, '');
        button.title = icon;
        const glyph = document.createElement('span');
        glyph.className = 'nowo-task-column__icon nowo-task-column__icon--emoji';
        glyph.setAttribute('aria-hidden', 'true');
        glyph.textContent = icon;
        const label = document.createElement('span');
        label.className = 'nowo-task-sr-only';
        label.textContent = icon;
        button.append(glyph, label);
        grid.append(button);
    }
}

/**
 * Since 2.10.0 (spec 216, FR-010): the host's warnings of the column (`data-column-delete-warning`, one per line, from
 * COLUMN_DELETE_CHECK) are shown in the dialog and added to the delete confirmation. Without warnings the help is hidden
 * and the confirmation is the bundle's own text.
 */
export function showDeleteWarning(form: HTMLFormElement, note: HTMLElement | null, warning: string): void {
    const base = form.dataset.confirmBase ?? form.getAttribute('data-confirm') ?? '';
    form.dataset.confirmBase = base;
    const text = warning.trim();
    form.setAttribute('data-confirm', text === '' || base === '' ? base + text : `${base}\n\n${text}`);
    if (note) {
        note.textContent = text;
        note.hidden = text === '';
    }
}

/**
 * Since 2.9.1 (audit JM-I7): the icon the dialog opens with. A stored icon the server would no longer store
 * (`data-column-icon-storable="0"`: a direct database write, a legacy `fa fa-bug`) opens as "No icon", so a rename is not
 * refused and saving drops that icon, with the grid and with a host widget alike.
 */
export function dialogIcon(trigger: HTMLElement): string {
    return trigger.dataset.columnIconStorable === '0' ? '' : (trigger.dataset.columnIcon ?? '');
}

/** Since 2.16.0 (spec 234): whether the edited column is flagged *humans only* (`data-column-humans-only="1"`). */
export function humansOnly(trigger: HTMLElement): boolean {
    return trigger.dataset.columnHumansOnly === '1';
}

/**
 * Since 3.6.0 (owner rule 2026-11-23, every dialog loads its content on demand) the edit-column dialog is the fragment of the
 * `column_edit` route: the server fills the name, colour, humans-only flag, update URL and delete form. This controller is the
 * fragment's (it carries the column's icon as `data-nowo-task-board-edit-column-modal-icon-value`) and shows a stored icon the
 * grid does not offer as the current choice; on the board page the same identifier only keeps the pencil from starting a column drag.
 */
export default class EditColumnModalController extends Controller<HTMLElement> {
    static targets = ['iconInput'];

    static values = { icon: String };

    declare readonly iconInputTarget: HTMLSelectElement | HTMLInputElement;

    declare readonly hasIconInputTarget: boolean;

    declare readonly hasIconValue: boolean;

    declare readonly iconValue: string;

    connect(): void {
        if (this.hasIconValue && this.hasIconInputTarget && this.iconInputTarget instanceof HTMLSelectElement) {
            showCurrentIcon(this.iconInputTarget, this.iconValue);
            // The icon grid (picker controller) follows the field.
            this.iconInputTarget.dispatchEvent(new Event('change', { bubbles: true }));
        }
    }

    stopDrag(event: Event): void {
        event.stopPropagation();
    }
}
