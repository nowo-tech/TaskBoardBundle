import { Controller } from '@hotwired/stimulus';
import { countActiveFilters, foldedKeys, readFilterFields } from './filter-fold-utils';

const COOKIE = 'nowo_ui_filters_collapsed';
const MAX_KEYS = 50;

type Messages = { collapse?: string; expand?: string; none?: string; one?: string; many?: string };

/**
 * Since 2.0.0: drives the list's filter fold with no host peer, on UiKit's markup and CSS and with its cookie, so the state
 * follows a host's `filters-collapse` pages. It has its own identifier: a host that registers the kit's peer must not get a
 * second controller on this toggle, which would toggle twice.
 */
export default class FilterFoldController extends Controller<HTMLElement> {
    static targets = ['toggle', 'summary'];

    static values = { key: String, messages: Object };

    declare readonly toggleTarget: HTMLButtonElement;

    declare readonly summaryTarget: HTMLElement;

    declare readonly keyValue: string;

    declare readonly messagesValue: Messages;

    toggle(): void {
        const collapsed = !this.element.classList.contains('is-collapsed');
        this.element.classList.toggle('is-collapsed', collapsed);
        this.toggleTarget.setAttribute('aria-expanded', collapsed ? 'false' : 'true');
        const label = (collapsed ? this.messagesValue.expand : this.messagesValue.collapse) ?? '';
        if (label !== '') {
            this.toggleTarget.setAttribute('aria-label', label);
            this.toggleTarget.title = label;
        }
        const count = countActiveFilters(readFilterFields(this.element.closest('form')));
        const text = (count === 0 ? this.messagesValue.none : count === 1 ? this.messagesValue.one : this.messagesValue.many) ?? '';
        this.summaryTarget.textContent = text.replace('%count%', String(count));
        this.summaryTarget.hidden = !collapsed;
        this.remember(collapsed);
    }

    private remember(collapsed: boolean): void {
        const keys = foldedKeys(document.cookie, COOKIE).filter((key) => key !== this.keyValue);
        if (collapsed) {
            keys.push(this.keyValue);
        }
        document.cookie = `${COOKIE}=${encodeURIComponent(keys.slice(-MAX_KEYS).join(','))}; path=/; max-age=31536000; samesite=lax`;
    }
}
