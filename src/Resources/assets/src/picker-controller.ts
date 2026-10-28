import { Controller } from '@hotwired/stimulus';

const HEX_COLOR = /^#[0-9a-f]{6}$/;

/**
 * Since 1.13: a visual picker over a real form field — the colour palette (swatches beside the <input type="color">)
 * and the icon grid (buttons over a hidden <select>) of the column dialogs. A click on an option writes its
 * data-value into the field and dispatches `input` / `change`; the options show the field's value (aria-pressed),
 * also when a dialog fills the field and dispatches `change`. `default` (optional) fills an empty field on connect.
 *
 * Since 1.14 an optional `custom` target (an <input type="color"> beside a hidden field, the task colour): a native
 * colour input cannot be empty, so the value lives in the hidden field ("" = no colour, a swatch with data-value="")
 * and the custom input writes its colour into it.
 */
export default class PickerController extends Controller<HTMLElement> {
    static targets = ['input', 'option', 'custom'];

    static values = {
        default: String,
    };

    declare readonly inputTarget: HTMLInputElement | HTMLSelectElement;

    declare readonly optionTargets: HTMLElement[];

    declare readonly customTarget: HTMLInputElement;

    declare readonly hasCustomTarget: boolean;

    declare readonly defaultValue: string;

    connect(): void {
        if (this.defaultValue !== '' && (this.inputTarget.value === '' || this.inputTarget.value === '#000000')) {
            this.inputTarget.value = this.defaultValue;
        }
        this.inputTarget.addEventListener('change', this.sync);
        this.inputTarget.addEventListener('input', this.sync);
        if (this.hasCustomTarget) {
            this.customTarget.addEventListener('input', this.pickCustom);
            this.customTarget.addEventListener('change', this.pickCustom);
        }
        this.sync();
    }

    disconnect(): void {
        this.inputTarget.removeEventListener('change', this.sync);
        this.inputTarget.removeEventListener('input', this.sync);
        if (this.hasCustomTarget) {
            this.customTarget.removeEventListener('input', this.pickCustom);
            this.customTarget.removeEventListener('change', this.pickCustom);
        }
    }

    pick(event: Event): void {
        this.write((event.currentTarget as HTMLElement).dataset.value ?? '');
    }

    private pickCustom = (): void => {
        this.write(this.customTarget.value);
    };

    private write(value: string): void {
        this.inputTarget.value = value;
        this.inputTarget.dispatchEvent(new Event('input', { bubbles: true }));
        this.inputTarget.dispatchEvent(new Event('change', { bubbles: true }));
    }

    private sync = (): void => {
        const current = this.inputTarget.value.toLowerCase();
        let matched = false;
        for (const option of this.optionTargets) {
            const pressed = (option.dataset.value ?? '').toLowerCase() === current;
            matched ||= pressed;
            option.setAttribute('aria-pressed', pressed ? 'true' : 'false');
        }

        if (this.hasCustomTarget) {
            if (HEX_COLOR.test(current)) {
                this.customTarget.value = current;
            }
            // A colour outside the palette is shown as the custom one.
            this.customTarget.classList.toggle('is-selected', !matched && current !== '');
        }
    };
}
