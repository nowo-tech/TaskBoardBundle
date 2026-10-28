import { Controller } from '@hotwired/stimulus';

/**
 * Since 1.13: a visual picker over a real form field — the colour palette (swatches beside the <input type="color">)
 * and the icon grid (buttons over a hidden <select>) of the column dialogs. A click on an option writes its
 * data-value into the field and dispatches `input` / `change`; the options show the field's value (aria-pressed),
 * also when a dialog fills the field and dispatches `change`. `default` (optional) fills an empty field on connect.
 */
export default class PickerController extends Controller<HTMLElement> {
    static targets = ['input', 'option'];

    static values = {
        default: String,
    };

    declare readonly inputTarget: HTMLInputElement | HTMLSelectElement;

    declare readonly optionTargets: HTMLElement[];

    declare readonly defaultValue: string;

    connect(): void {
        if (this.defaultValue !== '' && (this.inputTarget.value === '' || this.inputTarget.value === '#000000')) {
            this.inputTarget.value = this.defaultValue;
        }
        this.inputTarget.addEventListener('change', this.sync);
        this.inputTarget.addEventListener('input', this.sync);
        this.sync();
    }

    disconnect(): void {
        this.inputTarget.removeEventListener('change', this.sync);
        this.inputTarget.removeEventListener('input', this.sync);
    }

    pick(event: Event): void {
        const value = (event.currentTarget as HTMLElement).dataset.value ?? '';
        this.inputTarget.value = value;
        this.inputTarget.dispatchEvent(new Event('input', { bubbles: true }));
        this.inputTarget.dispatchEvent(new Event('change', { bubbles: true }));
    }

    private sync = (): void => {
        const current = this.inputTarget.value.toLowerCase();
        for (const option of this.optionTargets) {
            option.setAttribute('aria-pressed', (option.dataset.value ?? '').toLowerCase() === current ? 'true' : 'false');
        }
    };
}
