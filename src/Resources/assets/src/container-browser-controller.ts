import { Controller } from '@hotwired/stimulus';
import { fillMessage } from './sync-utils';

/**
 * Since 1.23: the container browser of the connection page (APG disclosure pattern + a radio group). Each container
 * is a disclosure button: the first time it opens, its level is fetched (POST, the page's CSRF token) and inserted
 * into its group; the group is aria-busy while loading and a polite live region says what happened. Without script the
 * same buttons submit to the containers route and the page reloads with the level open.
 */
export default class ContainerBrowserController extends Controller<HTMLFormElement> {
    static targets = ['status'];

    static values = {
        url: String,
        loading: { type: String, default: 'Loading…' },
        loaded: { type: String, default: 'Loaded: %name%.' },
        failed: { type: String, default: 'This level could not be loaded.' },
    };

    declare readonly statusTarget: HTMLElement;
    declare readonly hasStatusTarget: boolean;
    declare readonly urlValue: string;
    declare readonly loadingValue: string;
    declare readonly loadedValue: string;
    declare readonly failedValue: string;

    async toggle(event: Event): Promise<void> {
        event.preventDefault();
        const button = event.currentTarget as HTMLButtonElement;
        const group = document.getElementById(button.getAttribute('aria-controls') ?? '');
        if (group === null) {
            return;
        }
        const open = button.getAttribute('aria-expanded') !== 'true';
        button.setAttribute('aria-expanded', open ? 'true' : 'false');
        group.hidden = !open;
        if (!open || group.dataset.loaded === '1') {
            return;
        }

        group.setAttribute('aria-busy', 'true');
        this.announce(this.loadingValue);
        try {
            const body = new FormData();
            const token = this.element.querySelector<HTMLInputElement>('input[name="_token"]');
            body.set('_token', token?.value ?? '');
            body.set('provider', this.field('provider'));
            body.set('connection', this.field('connection'));
            body.set('parent', button.dataset.parent ?? '');
            const response = await fetch(this.urlValue, {
                method: 'POST',
                body,
                headers: { 'X-Requested-With': 'XMLHttpRequest' },
                credentials: 'same-origin',
            });
            if (!response.ok) {
                throw new Error(String(response.status));
            }
            group.innerHTML = await response.text();
            group.dataset.loaded = '1';
            this.announce(fillMessage(this.loadedValue, { name: button.dataset.label ?? '' }));
        } catch {
            button.setAttribute('aria-expanded', 'false');
            group.hidden = true;
            this.announce(this.failedValue);
        } finally {
            group.setAttribute('aria-busy', 'false');
        }
    }

    private field(name: string): string {
        return this.element.querySelector<HTMLInputElement>(`input[name$="[${name}]"]`)?.value ?? '';
    }

    private announce(text: string): void {
        if (this.hasStatusTarget) {
            this.statusTarget.textContent = text;
        }
    }
}
