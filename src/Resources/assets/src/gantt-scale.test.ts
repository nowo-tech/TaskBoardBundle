import { describe, expect, it } from 'vitest';
import { scaleRedirect, scaleToRemember } from './gantt-scale';

describe('scaleRedirect', () => {
    it('goes to the saved scale when the URL has none', () => {
        expect(scaleRedirect('https://host/board/1/gantt?x=1', 'days', 'weeks')).toBe('https://host/board/1/gantt?x=1&scale=weeks');
    });

    it('stays with a scale in the URL, the same scale, or nothing valid saved', () => {
        expect(scaleRedirect('https://host/gantt?scale=hours', 'hours', 'weeks')).toBeNull();
        expect(scaleRedirect('https://host/gantt', 'days', 'days')).toBeNull();
        expect(scaleRedirect('https://host/gantt', 'days', 'years')).toBeNull();
        expect(scaleRedirect('https://host/gantt', 'days', undefined)).toBeNull();
    });
});

describe('scaleToRemember', () => {
    it('remembers a valid scale of the URL only', () => {
        expect(scaleToRemember('https://host/gantt?scale=months')).toBe('months');
        expect(scaleToRemember('https://host/gantt?scale=decades')).toBeNull();
        expect(scaleToRemember('https://host/gantt')).toBeNull();
    });
});
