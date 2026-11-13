import { Controller } from '@hotwired/stimulus';
import { openKitDialog } from './dialog-utils';

/** An icon id (`<set>:<name>`, or a bare name stored before 2.9.0), drawn through the icon set rather than as text. */
const ICON_ID = /^[a-z0-9-]+(:[a-z0-9-]+)?$/;

/**
 * Opens the edit-column dialog and posts the updated name / color / icon (since 1.11); the delete form (since 1.11) posts to
 * the column's delete URL, asking first through `data-confirm`.
 */
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

export default class EditColumnModalController extends Controller<HTMLElement> {
    static targets = ['modal', 'form', 'nameInput', 'colorInput', 'iconInput', 'humansOnlyInput', 'deleteForm', 'deleteWarning', 'title'];

    static values = {
        updateUrlTemplate: String,
        deleteUrlTemplate: String,
        titleTemplate: String,
    };

    declare readonly iconInputTarget: HTMLSelectElement | HTMLInputElement;

    declare readonly hasIconInputTarget: boolean;

    declare readonly deleteFormTarget: HTMLFormElement;

    declare readonly hasDeleteFormTarget: boolean;

    declare readonly deleteWarningTarget: HTMLElement;

    declare readonly hasDeleteWarningTarget: boolean;

    declare deleteUrlTemplateValue: string;

    declare readonly modalTarget: HTMLDialogElement;

    declare readonly formTarget: HTMLFormElement;

    declare readonly nameInputTarget: HTMLInputElement;

    declare readonly colorInputTarget: HTMLInputElement;

    declare readonly titleTarget: HTMLElement;

    declare readonly hasColorInputTarget: boolean;

    declare readonly humansOnlyInputTarget: HTMLInputElement;

    declare readonly hasHumansOnlyInputTarget: boolean;

    declare updateUrlTemplateValue: string;

    declare titleTemplateValue: string;

    open(event: Event): void {
        const trigger = event.currentTarget as HTMLElement;
        const columnId = trigger.dataset.columnId ?? '';
        const columnName = trigger.dataset.columnName ?? '';
        const columnColor = trigger.dataset.columnColor ?? '#206bc4';

        if (columnId === '') {
            return;
        }

        this.formTarget.action = this.updateUrlTemplateValue.replace('COLUMN_ID', columnId);
        this.nameInputTarget.value = columnName;

        if (this.hasColorInputTarget) {
            this.colorInputTarget.value = columnColor !== '' ? columnColor : '#206bc4';
            this.colorInputTarget.dispatchEvent(new Event('change', { bubbles: true }));
        }

        if (this.hasIconInputTarget) {
            const icon = dialogIcon(trigger);
            // Since 2.9.0: a host widget (board.column_icon_form_type, e.g. an emoji picker) is a text field that would
            // refuse an icon id such as tabler:bug: it opens empty for such a column, so the dialog can still be saved.
            this.iconInputTarget.value = this.iconInputTarget instanceof HTMLSelectElement || !ICON_ID.test(icon) ? icon : '';
            if (this.iconInputTarget instanceof HTMLSelectElement) {
                showCurrentIcon(this.iconInputTarget, icon);
            }
            // The icon grid (picker controller) follows the field.
            this.iconInputTarget.dispatchEvent(new Event('change', { bubbles: true }));
        }

        // Since 2.16.0 (spec 234): the column's humans-only flag (`data-column-humans-only="1"`).
        if (this.hasHumansOnlyInputTarget) {
            this.humansOnlyInputTarget.checked = humansOnly(trigger);
        }

        if (this.hasDeleteFormTarget && this.deleteUrlTemplateValue !== '') {
            this.deleteFormTarget.action = this.deleteUrlTemplateValue.replace('COLUMN_ID', columnId);
            showDeleteWarning(this.deleteFormTarget, this.hasDeleteWarningTarget ? this.deleteWarningTarget : null, trigger.dataset.columnDeleteWarning ?? '');
        }

        if (this.hasTitleTarget && this.titleTemplateValue !== '') {
            this.titleTarget.textContent = this.titleTemplateValue.replace('%column%', columnName);
        }

        openKitDialog(this.modalTarget, trigger, this.nameInputTarget);
    }

    stopDrag(event: Event): void {
        event.stopPropagation();
    }
}
