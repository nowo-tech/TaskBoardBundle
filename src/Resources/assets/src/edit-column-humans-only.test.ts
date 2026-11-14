import { describe, expect, it } from 'vitest';
import { humansOnly } from './edit-column-modal-controller';

describe('the humans-only flag the edit dialog opens with (spec 234)', () => {
    function trigger(attributes: string): HTMLElement {
        document.body.innerHTML = `<button type="button" data-column-id="7" ${attributes}></button>`;

        return document.querySelector('button') as HTMLElement;
    }

    it('is checked only for a column flagged "1"', () => {
        expect(humansOnly(trigger('data-column-humans-only="1"'))).toBe(true);
        expect(humansOnly(trigger('data-column-humans-only="0"'))).toBe(false);
        expect(humansOnly(trigger(''))).toBe(false);
    });
});
