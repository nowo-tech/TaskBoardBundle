import { Controller } from '@hotwired/stimulus';
import { openKitDialog } from './dialog-utils';

/** Opens the add-column dialog on the board, focused on the name. */
export default class AddColumnModalController extends Controller<HTMLElement> {
    static targets = ['modal', 'nameInput'];

    declare readonly modalTarget: HTMLDialogElement;

    declare readonly nameInputTarget: HTMLInputElement;

    declare readonly hasNameInputTarget: boolean;

    open(event: Event): void {
        openKitDialog(this.modalTarget, event.currentTarget as Element, this.hasNameInputTarget ? this.nameInputTarget : null);
    }
}
