import { Controller } from '@hotwired/stimulus';
import { openKitDialog } from './dialog-utils';

/** Opens the add-task dialog with the selected column pre-filled. */
export default class AddTaskModalController extends Controller<HTMLElement> {
    static targets = ['modal', 'title', 'columnField', 'titleInput', 'dueField'];

    static values = {
        titleFormat: { type: String, default: 'Add task to %column%' },
    };

    declare readonly modalTarget: HTMLDialogElement;

    declare readonly titleTarget: HTMLElement;

    declare readonly columnFieldTarget: HTMLSelectElement | HTMLInputElement;

    declare readonly titleInputTarget: HTMLInputElement;

    declare readonly dueFieldTarget: HTMLInputElement;

    declare readonly hasDueFieldTarget: boolean;

    declare readonly hasTitleTarget: boolean;

    declare readonly hasColumnFieldTarget: boolean;

    declare readonly hasTitleInputTarget: boolean;

    declare readonly titleFormatValue: string;

    open(event: Event): void {
        const button = event.currentTarget as HTMLElement;
        const columnName = button.dataset.columnName ?? '';

        // Since 2.1.0: the calendar's add control names a day and no column, so the column keeps its default.
        if (this.hasColumnFieldTarget && button.dataset.columnId !== undefined) {
            this.columnFieldTarget.value = button.dataset.columnId;
        }
        if (this.hasDueFieldTarget && button.dataset.dueDate !== undefined) {
            this.dueFieldTarget.value = button.dataset.dueDate;
        }
        if (this.hasTitleTarget) {
            this.titleTarget.textContent = this.titleFormatValue.replace('%column%', columnName);
        }

        openKitDialog(this.modalTarget, button, this.hasTitleInputTarget ? this.titleInputTarget : null);
    }
}
