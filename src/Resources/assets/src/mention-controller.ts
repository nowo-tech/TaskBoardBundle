import { Controller } from '@hotwired/stimulus';
import { filterCandidates, findMentionQuery, insertMention, keptMentions, moveActive, parsePicked, type MentionCandidate, type MentionQuery } from './mention-utils';

/**
 * Since 1.16: @mentions in a comment box (a combobox over the textarea): typing "@" lists the candidates (the people
 * who can read the task), ↑ ↓ move, Enter or Tab pick, Escape closes; the active option is the textarea's
 * aria-activedescendant. Picked people are written "@Name " and sent in the hidden `mentions` field (only those still
 * named in the text) when the form is submitted.
 */
export default class MentionController extends Controller<HTMLFormElement> {
    static targets = ['input', 'list', 'mentions'];

    static values = { candidates: { type: Array, default: [] } };

    declare readonly inputTarget: HTMLTextAreaElement;

    declare readonly listTarget: HTMLUListElement;

    declare readonly mentionsTarget: HTMLInputElement;

    declare readonly candidatesValue: MentionCandidate[];

    private picked: MentionCandidate[] = [];

    private query: MentionQuery | null = null;

    private options: MentionCandidate[] = [];

    private active = -1;

    connect(): void {
        this.picked = parsePicked(this.mentionsTarget.value);
    }

    update(): void {
        this.query = findMentionQuery(this.inputTarget.value, this.inputTarget.selectionStart ?? this.inputTarget.value.length);
        this.options = this.query === null ? [] : filterCandidates(this.candidatesValue, this.query.query);
        this.active = this.options.length > 0 ? 0 : -1;
        this.render();
    }

    keydown(event: KeyboardEvent): void {
        if (this.options.length === 0) {
            return;
        }
        if (event.key === 'ArrowDown' || event.key === 'ArrowUp') {
            this.active = moveActive(this.active, this.options.length, event.key === 'ArrowDown' ? 1 : -1);
            this.render();
        } else if (event.key === 'Enter' || event.key === 'Tab') {
            this.pick(this.active);
        } else if (event.key === 'Escape') {
            event.stopPropagation();
            this.close();
        } else {
            return;
        }
        event.preventDefault();
    }

    blur(): void {
        this.close();
    }

    collect(): void {
        this.mentionsTarget.value = JSON.stringify(keptMentions(this.inputTarget.value, this.picked));
    }

    private pick(index: number): void {
        const candidate = this.options[index];
        if (candidate === undefined || this.query === null) {
            return;
        }
        const next = insertMention(this.inputTarget.value, this.query.start, this.inputTarget.selectionStart ?? this.inputTarget.value.length, candidate.label);
        this.inputTarget.value = next.text;
        this.inputTarget.setSelectionRange(next.caret, next.caret);
        this.picked.push(candidate);
        this.close();
    }

    private close(): void {
        this.options = [];
        this.active = -1;
        this.query = null;
        this.render();
    }

    private render(): void {
        const open = this.options.length > 0;
        this.listTarget.replaceChildren(
            ...this.options.map((candidate, index) => {
                const option = document.createElement('li');
                option.id = `${this.listTarget.id}-${index}`;
                option.setAttribute('role', 'option');
                option.setAttribute('aria-selected', index === this.active ? 'true' : 'false');
                option.className = 'nowo-task-mention-list__option';
                option.textContent = candidate.label;
                option.addEventListener('mousedown', (event) => {
                    event.preventDefault();
                    this.pick(index);
                });

                return option;
            }),
        );
        this.listTarget.hidden = !open;
        this.inputTarget.setAttribute('aria-expanded', open ? 'true' : 'false');
        if (open && this.active >= 0) {
            this.inputTarget.setAttribute('aria-activedescendant', `${this.listTarget.id}-${this.active}`);
        } else {
            this.inputTarget.removeAttribute('aria-activedescendant');
        }
    }
}
