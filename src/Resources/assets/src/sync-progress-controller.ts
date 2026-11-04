import { Controller } from '@hotwired/stimulus';
import { nextPollInterval, readProgress, shouldAnnounce } from './sync-utils';

/**
 * Since 1.23: polls the progress endpoint of a running sync and updates the <progress> bar and the live region —
 * every `interval` ms, backing off when nothing changes, paused while the tab is hidden, stopping on a final state
 * (then the "Show the result" link appears). The live region changes at most every 5 s, and on the final state.
 */
export default class SyncProgressController extends Controller<HTMLElement> {
    static targets = ['bar', 'status', 'done', 'cancel'];

    static values = { url: String, interval: { type: Number, default: 2000 } };

    declare readonly barTarget: HTMLProgressElement;
    declare readonly statusTarget: HTMLElement;
    declare readonly doneTarget: HTMLElement;
    declare readonly hasCancelTarget: boolean;
    declare readonly cancelTarget: HTMLElement;
    declare readonly urlValue: string;
    declare readonly intervalValue: number;

    private timer: number | undefined;
    private delay = 2000;
    private lastText = '';
    private lastAnnounced = 0;
    private lastProcessed = -1;

    connect(): void {
        this.delay = this.intervalValue;
        this.lastText = this.statusTarget.textContent?.trim() ?? '';
        this.lastAnnounced = Date.now();
        this.schedule();
    }

    disconnect(): void {
        window.clearTimeout(this.timer);
    }

    private schedule(): void {
        window.clearTimeout(this.timer);
        this.timer = window.setTimeout(() => void this.poll(), this.delay);
    }

    private async poll(): Promise<void> {
        if (document.hidden) {
            this.schedule();

            return;
        }
        let terminal = false;
        try {
            const response = await fetch(this.urlValue, { headers: { Accept: 'application/json' }, credentials: 'same-origin' });
            const progress = readProgress(await response.json());
            if (progress === null) {
                throw new Error('progress');
            }
            terminal = progress.terminal;
            if (progress.total !== null) {
                this.barTarget.max = progress.total;
                this.barTarget.value = Math.min(progress.processed, progress.total);
            }
            const now = Date.now();
            if (shouldAnnounce(now, this.lastAnnounced, progress.summary, this.lastText, terminal)) {
                this.statusTarget.textContent = progress.summary;
                this.lastText = progress.summary;
                this.lastAnnounced = now;
            }
            const changed = progress.processed !== this.lastProcessed;
            this.lastProcessed = progress.processed;
            this.delay = nextPollInterval(this.delay, this.intervalValue, changed);
        } catch {
            this.delay = nextPollInterval(this.delay, this.intervalValue, false);
        }
        if (terminal) {
            if (this.barTarget.max > 0 && this.barTarget.hasAttribute('value')) {
                this.barTarget.value = this.barTarget.max;
            }
            this.doneTarget.hidden = false;
            if (this.hasCancelTarget) {
                this.cancelTarget.hidden = true;
            }

            return;
        }
        this.schedule();
    }
}
