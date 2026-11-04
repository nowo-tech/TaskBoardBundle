import { describe, expect, it } from 'vitest';
import { fillMessage, nextPollInterval, readProgress, shouldAnnounce } from './sync-utils';

describe('nextPollInterval', () => {
    it('resets to the base after a change and backs off otherwise, capped', () => {
        expect(nextPollInterval(8000, 2000, true)).toBe(2000);
        expect(nextPollInterval(2000, 2000, false)).toBe(4000);
        expect(nextPollInterval(8000, 2000, false)).toBe(10000);
        expect(nextPollInterval(500, 2000, false)).toBe(2000);
        expect(nextPollInterval(3000, 2000, false, 5000)).toBe(5000);
    });
});

describe('shouldAnnounce', () => {
    it('throttles progress but always announces a final state', () => {
        expect(shouldAnnounce(1000, 0, 'a', '', false)).toBe(false);
        expect(shouldAnnounce(6000, 0, 'a', '', false)).toBe(true);
        expect(shouldAnnounce(1000, 0, 'done', 'a', true)).toBe(true);
        expect(shouldAnnounce(9000, 0, 'a', 'a', true)).toBe(false);
        expect(shouldAnnounce(9000, 0, '', 'a', true)).toBe(false);
        expect(shouldAnnounce(3000, 0, 'b', 'a', false, 2000)).toBe(true);
    });
});

describe('fillMessage', () => {
    it('replaces every placeholder', () => {
        expect(fillMessage('Loaded: %name% (%name%)', { name: 'Backlog' })).toBe('Loaded: Backlog (Backlog)');
        expect(fillMessage('Loading…', {})).toBe('Loading…');
    });
});

describe('readProgress', () => {
    it('reads a payload and refuses anything else', () => {
        expect(readProgress(null)).toBeNull();
        expect(readProgress('x')).toBeNull();
        expect(readProgress({ terminal: true })).toBeNull();
        expect(readProgress({ status: 'running', terminal: false, processed: 3, total: 10, summary: 's' })).toEqual({
            status: 'running', terminal: false, processed: 3, total: 10, summary: 's',
        });
        expect(readProgress({ status: 'succeeded', terminal: true, processed: 'x', total: 0, summary: 1 })).toEqual({
            status: 'succeeded', terminal: true, processed: 0, total: null, summary: '',
        });
    });
});
