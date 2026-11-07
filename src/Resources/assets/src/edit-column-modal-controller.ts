import { Controller } from '@hotwired/stimulus';
import { openKitDialog } from './dialog-utils';

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

    declare readonly iconInputTarget: HTMLSelectElement;

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
            this.iconInputTarget.value = trigger.dataset.columnIcon ?? '';
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
