import { Controller } from '@hotwired/stimulus';
import { sourceAnchor, withAnchor } from './anchor-utils';

/**
 * Since 1.24 (help_link.anchor_from: source): keeps the `#anchor` of the import help link on the selected import
 * source. The select is found with the `selector` value inside the element's form (it is painted by a form type, so
 * it carries no target); `change` events of the whole document are watched because the link sits beside the select,
 * not around it.
 */
export default class SelectAnchorController extends Controller<HTMLElement> {
    static targets = ['link'];
    static values = { selector: { type: String, default: 'select' } };

    declare readonly linkTargets: HTMLAnchorElement[];
    declare selectorValue: string;

    connect(): void {
        document.addEventListener('change', this.update);
        this.update();
    }

    disconnect(): void {
        document.removeEventListener('change', this.update);
    }

    update = (): void => {
        const scope = this.element.closest('form') ?? this.element;
        const select = scope.querySelector<HTMLSelectElement>(this.selectorValue);
        if (select === null) {
            return;
        }

        const anchor = sourceAnchor(select.value);
        this.linkTargets.forEach((link) => {
            link.setAttribute('href', withAnchor(link.getAttribute('href') ?? '', anchor));
        });
    };
}
