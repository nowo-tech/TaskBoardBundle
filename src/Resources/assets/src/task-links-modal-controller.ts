import { Controller } from '@hotwired/stimulus';
import { openKitDialog } from './dialog-utils';

/** Add and edit task links in a dialog on the task detail page. Removing one asks first through `data-confirm`. */
export default class TaskLinksModalController extends Controller<HTMLElement> {
    static targets = [
        'modal',
        'form',
        'deleteForm',
        'deleteButton',
        'title',
        'linkType',
        'url',
        'label',
        'externalId',
    ];

    static values = {
        createUrl: String,
        updateUrl: String,
        removeUrl: String,
        addTitle: String,
        editTitle: String,
    };

    declare readonly modalTarget: HTMLDialogElement;

    declare readonly formTarget: HTMLFormElement;

    declare readonly deleteFormTarget: HTMLFormElement;

    declare readonly deleteButtonTarget: HTMLButtonElement;

    declare readonly titleTarget: HTMLElement;

    declare readonly linkTypeTarget: HTMLSelectElement;

    declare readonly urlTarget: HTMLInputElement;

    declare readonly labelTarget: HTMLInputElement;

    declare readonly externalIdTarget: HTMLInputElement;

    declare readonly hasDeleteFormTarget: boolean;

    declare readonly hasDeleteButtonTarget: boolean;

    declare readonly createUrlValue: string;

    declare readonly updateUrlValue: string;

    declare readonly removeUrlValue: string;

    declare readonly addTitleValue: string;

    declare readonly editTitleValue: string;

    openAdd(event: Event): void {
        event.preventDefault();
        this.resetForm(this.createUrlValue);
        this.titleTarget.textContent = this.addTitleValue;
        this.toggleDeleteForm(false);
        openKitDialog(this.modalTarget, event.currentTarget as Element, this.urlTarget);
    }

    openEdit(event: Event): void {
        event.preventDefault();

        const trigger = event.currentTarget as HTMLElement;
        const linkId = trigger.dataset.linkId ?? '';
        if (linkId === '') {
            return;
        }

        this.resetForm(this.updateUrlValue.replace('LINK_ID', linkId));
        this.titleTarget.textContent = this.editTitleValue;
        this.linkTypeTarget.value = trigger.dataset.linkType ?? this.linkTypeTarget.value;
        this.urlTarget.value = trigger.dataset.linkUrl ?? '';
        this.labelTarget.value = trigger.dataset.linkLabel ?? '';
        this.externalIdTarget.value = trigger.dataset.linkExternalId ?? '';

        if (this.hasDeleteFormTarget) {
            this.deleteFormTarget.action = this.removeUrlValue.replace('LINK_ID', linkId);
        }

        this.toggleDeleteForm(true);
        openKitDialog(this.modalTarget, event.currentTarget as Element, this.urlTarget);
    }

    private resetForm(action: string): void {
        this.formTarget.action = action;
        this.formTarget.reset();
    }

    private toggleDeleteForm(visible: boolean): void {
        if (this.hasDeleteFormTarget) {
            this.deleteFormTarget.hidden = !visible;
        }

        if (this.hasDeleteButtonTarget) {
            this.deleteButtonTarget.hidden = !visible;
        }
    }
}
