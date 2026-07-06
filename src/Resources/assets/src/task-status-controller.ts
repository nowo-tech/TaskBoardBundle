import { Controller } from '@hotwired/stimulus';

/**
 * ClickUp-style status picker: toggle column dropdown, close on outside click.
 */
export default class TaskStatusController extends Controller<HTMLElement> {
    static targets = ['menu', 'toggle'];

    declare readonly menuTarget: HTMLElement;

    declare readonly toggleTarget: HTMLElement;

    private boundCloseOnOutsideClick = this.closeOnOutsideClick.bind(this);

    connect(): void {
        document.addEventListener('click', this.boundCloseOnOutsideClick);
    }

    disconnect(): void {
        document.removeEventListener('click', this.boundCloseOnOutsideClick);
    }

    toggle(event: Event): void {
        event.preventDefault();
        event.stopPropagation();
        this.menuTarget.classList.toggle('show');
        this.toggleTarget.classList.toggle('show', this.menuTarget.classList.contains('show'));
    }

    close(): void {
        this.menuTarget.classList.remove('show');
        this.toggleTarget.classList.remove('show');
    }

    private closeOnOutsideClick(event: Event): void {
        if (!(event.target instanceof Node) || this.element.contains(event.target)) {
            return;
        }

        this.close();
    }
}
