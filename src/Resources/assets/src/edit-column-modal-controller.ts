import { Controller } from '@hotwired/stimulus';
import { openKitDialog } from './dialog-utils';

/** An icon id (`<set>:<name>`, or a bare name stored before 2.9.0), drawn through the icon set rather than as text. */
const ICON_ID = /^[a-z0-9-]+(:[a-z0-9-]+)?$/;

/**
 * Opens the edit-column dialog and posts the updated name / color / icon (since 1.11); the delete form (since 1.11) posts to
 * the column's delete URL, asking first through `data-confirm`.
 */
export default class EditColumnModalController extends Controller<HTMLElement> {
    static targets = ['modal', 'form', 'nameInput', 'colorInput', 'iconInput', 'deleteForm', 'title'];

    static values = {
        updateUrlTemplate: String,
        deleteUrlTemplate: String,
        titleTemplate: String,
    };

    declare readonly iconInputTarget: HTMLSelectElement | HTMLInputElement;

    declare readonly hasIconInputTarget: boolean;

    declare readonly deleteFormTarget: HTMLFormElement;

    declare readonly hasDeleteFormTarget: boolean;

    declare deleteUrlTemplateValue: string;

    declare readonly modalTarget: HTMLDialogElement;

    declare readonly formTarget: HTMLFormElement;

    declare readonly nameInputTarget: HTMLInputElement;

    declare readonly colorInputTarget: HTMLInputElement;

    declare readonly titleTarget: HTMLElement;

    declare readonly hasColorInputTarget: boolean;

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
            const icon = trigger.dataset.columnIcon ?? '';
            // Since 2.9.0: a host widget (board.column_icon_form_type, e.g. an emoji picker) is a text field that would
            // refuse an icon id such as tabler:bug: it opens empty for such a column, so the dialog can still be saved.
            this.iconInputTarget.value = this.iconInputTarget instanceof HTMLSelectElement || !ICON_ID.test(icon) ? icon : '';
            // The icon grid (picker controller) follows the field.
            this.iconInputTarget.dispatchEvent(new Event('change', { bubbles: true }));
        }

        if (this.hasDeleteFormTarget && this.deleteUrlTemplateValue !== '') {
            this.deleteFormTarget.action = this.deleteUrlTemplateValue.replace('COLUMN_ID', columnId);
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
