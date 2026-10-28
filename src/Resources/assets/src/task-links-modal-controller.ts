import { Controller } from '@hotwired/stimulus';
import { ModalToggle } from './modal-toggle';

/**
 * Add/edit task links in a dialog on the task detail page (since 1.14 a native <dialog>; the Bootstrap `.modal`
 * markup of older template overrides keeps working).
 */
export default class TaskLinksModalController extends Controller<HTMLElement> {
    static targets = [
        'modal',
        'backdrop',
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

    declare readonly modalTarget: HTMLElement | HTMLDialogElement;

    declare readonly backdropTarget: HTMLElement;

    declare readonly formTarget: HTMLFormElement;

    declare readonly deleteFormTarget: HTMLFormElement;

    declare readonly deleteButtonTarget: HTMLButtonElement;

    declare readonly titleTarget: HTMLElement;

    declare readonly linkTypeTarget: HTMLSelectElement;

    declare readonly urlTarget: HTMLInputElement;

    declare readonly labelTarget: HTMLInputElement;

    declare readonly externalIdTarget: HTMLInputElement;

    declare readonly hasBackdropTarget: boolean;

    declare readonly hasDeleteFormTarget: boolean;

    declare readonly hasDeleteButtonTarget: boolean;

    declare readonly createUrlValue: string;

    declare readonly updateUrlValue: string;

    declare readonly removeUrlValue: string;

    declare readonly addTitleValue: string;

    declare readonly editTitleValue: string;

    private toggle: ModalToggle | null = null;

    openAdd(event: Event): void {
        event.preventDefault();
        this.resetForm(this.createUrlValue);
        this.titleTarget.textContent = this.addTitleValue;
        this.toggleDeleteForm(false);
        this.modal().show();
        this.focusUrlField();
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
        this.modal().show();
        this.focusUrlField();
    }

    close(): void {
        this.modal().hide();
    }

    closeOnBackdrop(event: Event): void {
        if (this.modal().isBackdrop(event.target)) {
            this.close();
        }
    }

    /** Since 1.12: the confirmation of "Remove link" (no inline handler, so a strict CSP keeps it working). */
    confirmDelete(event: Event): void {
        const message = (event.currentTarget as HTMLElement | null)?.dataset.confirmMessage ?? '';
        if ('' !== message && !window.confirm(message)) {
            event.preventDefault();
        }
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

    private focusUrlField(): void {
        window.requestAnimationFrame(() => this.urlTarget.focus());
    }

    private modal(): ModalToggle {
        this.toggle ??= new ModalToggle(this.modalTarget, this.hasBackdropTarget ? this.backdropTarget : null, () => this.close());

        return this.toggle;
    }
}
