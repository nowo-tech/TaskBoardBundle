import { Controller } from '@hotwired/stimulus';
import { openKitDialog } from './dialog-utils';

/** Opens the add-task dialog with the selected column pre-filled. */
export default class AddTaskModalController extends Controller<HTMLElement> {
    static targets = ['modal', 'title', 'columnField', 'titleInput'];

    static values = {
        titleFormat: { type: String, default: 'Add task to %column%' },
    };

    declare readonly modalTarget: HTMLDialogElement;

    declare readonly titleTarget: HTMLElement;

    declare readonly columnFieldTarget: HTMLSelectElement | HTMLInputElement;

    declare readonly titleInputTarget: HTMLInputElement;

    declare readonly hasTitleTarget: boolean;

    declare readonly hasColumnFieldTarget: boolean;

    declare readonly hasTitleInputTarget: boolean;

    declare readonly titleFormatValue: string;

    open(event: Event): void {
        const button = event.currentTarget as HTMLElement;
        const columnName = button.dataset.columnName ?? '';

        if (this.hasColumnFieldTarget) {
            this.columnFieldTarget.value = button.dataset.columnId ?? '';
        }
        if (this.hasTitleTarget) {
            this.titleTarget.textContent = this.titleFormatValue.replace('%column%', columnName);
        }

        openKitDialog(this.modalTarget, button, this.hasTitleInputTarget ? this.titleInputTarget : null);
    }
}
