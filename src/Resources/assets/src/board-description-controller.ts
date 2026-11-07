import { Controller } from '@hotwired/stimulus';

/**
 * Since 1.30.0 (spec 211): folds a board's Markdown description after three lines (`is-folded`, CSS line clamp) with a
 * Show more button, only when it does not fit. Without this controller the text shows whole.
 */
export default class BoardDescriptionController extends Controller<HTMLElement> {
    static targets = ['text', 'toggle'];

    static values = { more: String, less: String };

    declare readonly textTarget: HTMLElement;

    declare readonly toggleTarget: HTMLButtonElement;

    declare readonly moreValue: string;

    declare readonly lessValue: string;

    connect(): void {
        this.textTarget.classList.add('is-folded');
        this.toggleTarget.hidden = this.textTarget.scrollHeight <= this.textTarget.clientHeight;
    }

    toggle(): void {
        const folded = this.textTarget.classList.toggle('is-folded');
        this.toggleTarget.setAttribute('aria-expanded', folded ? 'false' : 'true');
        this.toggleTarget.textContent = folded ? this.moreValue : this.lessValue;
    }
}
