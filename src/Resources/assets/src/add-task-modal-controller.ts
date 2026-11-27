import { Controller } from '@hotwired/stimulus';

/**
 * Since 3.6.0 (owner rule 2026-11-23, every dialog loads its content on demand) the add-task dialog is fetched when it opens
 * (UiKit's remote form dialog), so this controller has two small jobs: on the board page a template picked in the dialog
 * reloads the page with the template applied, and the page then opens the dialog again on its own (`open-on-connect`, by
 * clicking the page's hidden opener), and inside the fragment the picker carries the typed title over (`applyTemplate`).
 */
export default class AddTaskModalController extends Controller<HTMLElement> {
    static targets = ['opener', 'titleInput', 'templateTitle'];

    static values = {
        openOnConnect: { type: Boolean, default: false },
    };

    declare readonly openerTarget: HTMLElement;

    declare readonly hasOpenerTarget: boolean;

    declare readonly titleInputTarget: HTMLInputElement;

    declare readonly hasTitleInputTarget: boolean;

    declare readonly templateTitleTarget: HTMLInputElement;

    declare readonly hasTemplateTitleTarget: boolean;

    declare readonly openOnConnectValue: boolean;

    connect(): void {
        if (this.openOnConnectValue && this.hasOpenerTarget) {
            this.openerTarget.click();
        }
    }

    /** The template picker changed: carry the typed title over and load the board with the template applied. */
    applyTemplate(event: Event): void {
        const select = event.currentTarget as HTMLSelectElement;
        if (this.hasTemplateTitleTarget && this.hasTitleInputTarget) {
            this.templateTitleTarget.value = this.titleInputTarget.value;
        }
        select.form?.requestSubmit();
    }
}
