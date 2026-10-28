import { Controller } from '@hotwired/stimulus';
import { matchesOption } from './option-filter-utils';

/**
 * Since 1.15: the searchable task picker of the "Add dependency" dialog — typing in the `search` field hides the
 * options of the `select` list that do not match (the selected one stays); `empty` shows when nothing matches.
 * Enter in the search field picks the first visible option instead of submitting.
 */
export default class OptionFilterController extends Controller<HTMLElement> {
    static targets = ['search', 'select', 'empty'];

    declare readonly searchTarget: HTMLInputElement;

    declare readonly selectTarget: HTMLSelectElement;

    declare readonly emptyTarget: HTMLElement;

    declare readonly hasEmptyTarget: boolean;

    filter(): void {
        const query = this.searchTarget.value;
        let visible = 0;
        for (const option of Array.from(this.selectTarget.options)) {
            const show = option.selected || matchesOption(option.text, query);
            option.hidden = !show;
            visible += show ? 1 : 0;
        }
        if (this.hasEmptyTarget) {
            this.emptyTarget.hidden = visible > 0;
        }
    }

    prevent(event: Event): void {
        event.preventDefault();
        const first = Array.from(this.selectTarget.options).find((option) => !option.hidden);
        if (first !== undefined) {
            first.selected = true;
            this.selectTarget.focus();
        }
    }
}
