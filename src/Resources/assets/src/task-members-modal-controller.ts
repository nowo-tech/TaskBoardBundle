import { Controller } from '@hotwired/stimulus';
import { openKitDialog } from './dialog-utils';

/** "Manage people" dialog on the task detail page, focused on the person field. */
export default class TaskMembersModalController extends Controller<HTMLElement> {
    static targets = ['modal', 'userField'];

    declare readonly modalTarget: HTMLDialogElement;

    declare readonly userFieldTarget: HTMLSelectElement;

    declare readonly hasUserFieldTarget: boolean;

    open(event: Event): void {
        event.preventDefault();
        openKitDialog(this.modalTarget, event.currentTarget as Element, this.hasUserFieldTarget ? this.userFieldTarget : null);
    }
}
