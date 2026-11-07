import { Controller } from '@hotwired/stimulus';

const getAttribute = Element.prototype.getAttribute;

/**
 * Since 1.30.0 (spec 211): the create form's scope step. Without this controller every field shows and the server uses the
 * chosen scope. Step 1 shows the scopes; Continue opens step 2 with that scope's fields and the common ones. A form answered
 * with errors, or a preselected or only scope, opens on step 2.
 *
 * Back and Create may be lifted out of this element by a host's remote dialog, so they are found by the form and handled on
 * the document. `step-shown` fires on the element when step 2 opens, for a host widget that must measure itself.
 */
export default class BoardCreateStepsController extends Controller<HTMLFormElement> {
    static targets = ['scopes', 'details', 'scopeFields'];

    static values = { start: { type: Number, default: 1 } };

    declare readonly scopesTarget: HTMLElement;

    declare readonly detailsTarget: HTMLElement;

    declare readonly scopeFieldsTargets: HTMLElement[];

    declare readonly startValue: number;

    private readonly onBack = (event: Event): void => {
        const target = event.target instanceof Element ? event.target.closest('[data-nowo-ui-dialog-back]') : null;
        if (target !== null && getAttribute.call(target, 'data-nowo-task-board-create-form') === getAttribute.call(this.element, 'id')) {
            event.preventDefault();
            this.showScopes();
        }
    };

    connect(): void {
        document.addEventListener('click', this.onBack);
        if (this.startValue === 2 && this.chosen() !== null) {
            this.showDetails();
        } else {
            this.showScopes();
        }
    }

    disconnect(): void {
        document.removeEventListener('click', this.onBack);
    }

    next(event?: Event): void {
        event?.preventDefault();
        if (this.chosen() !== null) {
            this.showDetails();
        }
    }

    private chosen(): string | null {
        const radio = this.scopesTarget.querySelector<HTMLInputElement>('input[type="radio"]:checked');

        return radio === null ? null : radio.value;
    }

    private showScopes(): void {
        this.scopesTarget.hidden = false;
        this.detailsTarget.hidden = true;
        this.setSubmitsHidden(true);
        this.setBacksHidden(true);
    }

    private showDetails(): void {
        const chosen = this.chosen();
        this.scopesTarget.hidden = true;
        this.detailsTarget.hidden = false;
        this.scopeFieldsTargets.forEach((fields) => {
            fields.hidden = getAttribute.call(fields, 'data-scope') !== chosen;
        });
        this.setSubmitsHidden(false);
        this.setBacksHidden(this.scopeCount() <= 1);
        this.dispatch('step-shown', { detail: { scope: chosen } });
    }

    private scopeCount(): number {
        return this.scopesTarget.querySelectorAll('input[type="radio"]').length;
    }

    private setSubmitsHidden(hidden: boolean): void {
        Array.from(this.element.elements).forEach((element) => {
            if (element instanceof HTMLButtonElement && getAttribute.call(element, 'type') === 'submit') {
                element.hidden = hidden;
            }
        });
    }

    private setBacksHidden(hidden: boolean): void {
        const id = getAttribute.call(this.element, 'id');
        document.querySelectorAll<HTMLElement>('[data-nowo-ui-dialog-back]').forEach((back) => {
            if (getAttribute.call(back, 'data-nowo-task-board-create-form') === id) {
                back.hidden = hidden;
            }
        });
    }
}
