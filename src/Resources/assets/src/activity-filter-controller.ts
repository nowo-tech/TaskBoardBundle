import { Controller } from '@hotwired/stimulus';

/**
 * Since 1.16: the task activity stream — "All activity" / "Comments" (aria-pressed buttons; `is-comments-only` on the
 * list hides the history), editing a comment in place (its text hides, its comment box shows) and the confirmation
 * before deleting one.
 */
export default class ActivityFilterController extends Controller<HTMLElement> {
    static targets = ['button', 'list'];

    declare readonly buttonTargets: HTMLButtonElement[];

    declare readonly listTarget: HTMLElement;

    show(event: Event): void {
        const pressed = event.currentTarget as HTMLButtonElement;
        this.buttonTargets.forEach((button) => button.setAttribute('aria-pressed', button === pressed ? 'true' : 'false'));
        this.listTarget.classList.toggle('is-comments-only', pressed.dataset.filter === 'comments');
    }

    edit(event: Event): void {
        const button = event.currentTarget as HTMLButtonElement;
        const item = button.closest('[data-comment-id]');
        const box = item?.querySelector<HTMLElement>('.nowo-task-comment__edit');
        const text = item?.querySelector<HTMLElement>('[data-comment-text]');
        if (!box || !text) {
            return;
        }
        box.hidden = false;
        text.hidden = true;
        button.setAttribute('aria-expanded', 'true');
        box.querySelector<HTMLTextAreaElement>('textarea')?.focus();
    }

    cancelEdit(event: Event): void {
        const item = (event.currentTarget as HTMLElement).closest('[data-comment-id]');
        const box = item?.querySelector<HTMLElement>('.nowo-task-comment__edit');
        const text = item?.querySelector<HTMLElement>('[data-comment-text]');
        if (box && text) {
            box.hidden = true;
            text.hidden = false;
            item?.querySelector('[aria-controls]')?.setAttribute('aria-expanded', 'false');
        }
    }

    confirm(event: Event): void {
        const message = (event.currentTarget as HTMLElement).dataset.confirm ?? '';
        if (message !== '' && !window.confirm(message)) {
            event.preventDefault();
        }
    }
}
