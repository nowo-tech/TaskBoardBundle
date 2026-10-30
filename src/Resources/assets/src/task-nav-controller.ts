import { Controller } from '@hotwired/stimulus';
import { stepOf } from './task-nav-utils';

/**
 * Since 1.20: previous / next on the task page (manage/_task_navigation.html.twig). Alt+← / Alt+→, or K / J while the
 * focus is not in a field, follow the previous / next link; leaving with unsaved edits of the task form asks first
 * (`unsaved`). A click a host already handled (e.g. a drawer that opens the task in place) is left to it.
 */
export default class TaskNavController extends Controller<HTMLElement> {
    static targets = ['previous', 'next'];

    static values = { unsaved: String, keys: { type: Boolean, default: true } };

    declare readonly hasPreviousTarget: boolean;
    declare readonly previousTarget: HTMLAnchorElement;
    declare readonly hasNextTarget: boolean;
    declare readonly nextTarget: HTMLAnchorElement;
    declare readonly unsavedValue: string;
    declare readonly keysValue: boolean;

    private dirty = false;

    private readonly onInput = (event: Event): void => {
        if (event.target instanceof Element && null !== event.target.closest('#nowo-task-edit-form, [form="nowo-task-edit-form"]')) {
            this.dirty = true;
        }
    };

    private readonly onSubmit = (): void => {
        this.dirty = false;
    };

    private readonly onKeydown = (event: KeyboardEvent): void => {
        const step = this.keysValue && !event.defaultPrevented ? stepOf(event) : null;
        const link = 'previous' === step && this.hasPreviousTarget ? this.previousTarget : 'next' === step && this.hasNextTarget ? this.nextTarget : null;
        if (null === link) {
            return;
        }
        event.preventDefault();
        link.click();
    };

    connect(): void {
        document.addEventListener('input', this.onInput);
        document.addEventListener('submit', this.onSubmit);
        window.addEventListener('keydown', this.onKeydown);
    }

    disconnect(): void {
        document.removeEventListener('input', this.onInput);
        document.removeEventListener('submit', this.onSubmit);
        window.removeEventListener('keydown', this.onKeydown);
    }

    /** A step link: unsaved edits ask before leaving the page. */
    leave(event: Event): void {
        if (event.defaultPrevented || !this.dirty || '' === this.unsavedValue) {
            return;
        }
        if (!window.confirm(this.unsavedValue)) {
            event.preventDefault();
        }
    }
}
