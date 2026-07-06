import { Controller } from '@hotwired/stimulus';

/**
 * Add/edit task links in a modal on the task detail page.
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

    declare readonly modalTarget: HTMLElement;

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

    openAdd(event: Event): void {
        event.preventDefault();
        this.resetForm(this.createUrlValue);
        this.titleTarget.textContent = this.addTitleValue;
        this.toggleDeleteForm(false);
        this.showModal();
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
        this.showModal();
        this.focusUrlField();
    }

    close(): void {
        this.hideModal();
    }

    closeOnBackdrop(event: Event): void {
        if (event.target === this.backdropTarget) {
            this.close();
        }
    }

    closeOnEscape = (event: KeyboardEvent): void => {
        if (event.key === 'Escape') {
            this.close();
        }
    };

    private resetForm(action: string): void {
        this.formTarget.action = action;
        this.formTarget.reset();
    }

    private toggleDeleteForm(visible: boolean): void {
        if (this.hasDeleteFormTarget) {
            this.deleteFormTarget.classList.toggle('d-none', !visible);
        }

        if (this.hasDeleteButtonTarget) {
            this.deleteButtonTarget.classList.toggle('d-none', !visible);
        }
    }

    private focusUrlField(): void {
        window.requestAnimationFrame(() => this.urlTarget.focus());
    }

    private showModal(): void {
        this.modalTarget.classList.add('show');
        this.modalTarget.style.display = 'block';
        this.modalTarget.removeAttribute('aria-hidden');
        this.modalTarget.setAttribute('aria-modal', 'true');
        document.body.classList.add('modal-open');

        if (this.hasBackdropTarget) {
            this.backdropTarget.classList.add('show');
        }

        document.addEventListener('keydown', this.closeOnEscape);
    }

    private hideModal(): void {
        this.modalTarget.classList.remove('show');
        this.modalTarget.style.display = 'none';
        this.modalTarget.setAttribute('aria-hidden', 'true');
        this.modalTarget.removeAttribute('aria-modal');
        document.body.classList.remove('modal-open');

        if (this.hasBackdropTarget) {
            this.backdropTarget.classList.remove('show');
        }

        document.removeEventListener('keydown', this.closeOnEscape);
    }
}
