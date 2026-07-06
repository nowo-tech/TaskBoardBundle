import { Controller } from '@hotwired/stimulus';

/**
 * Expand/collapse subtask sections on the task detail page.
 */
export default class SubtaskTreeController extends Controller<HTMLElement> {
    static targets = ['toggle', 'list'];

    declare readonly toggleTarget: HTMLButtonElement;

    declare readonly listTarget: HTMLElement;

    connect(): void {
        this.syncVisibility();
    }

    toggle(): void {
        const expanded = this.toggleTarget.getAttribute('aria-expanded') === 'true';
        this.toggleTarget.setAttribute('aria-expanded', expanded ? 'false' : 'true');
        this.syncVisibility();
    }

    private syncVisibility(): void {
        const expanded = this.toggleTarget.getAttribute('aria-expanded') === 'true';
        this.listTarget.hidden = !expanded;
    }
}
