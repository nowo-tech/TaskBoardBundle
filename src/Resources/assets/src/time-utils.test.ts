import { describe, expect, it } from 'vitest';
import { formatDeviation, formatMinutes, readTimeRow, sumTimes } from './time-utils';

describe('formatMinutes', () => {
    it('formats minutes as h / min', () => {
        expect(formatMinutes(null)).toBe('—');
        expect(formatMinutes(0)).toBe('0 min');
        expect(formatMinutes(45)).toBe('45 min');
        expect(formatMinutes(120)).toBe('2 h');
        expect(formatMinutes(90)).toBe('1 h 30 min');
    });

    it('signs a difference', () => {
        expect(formatMinutes(20, true)).toBe('+20 min');
        expect(formatMinutes(-60, true)).toBe('−1 h');
        expect(formatMinutes(-5)).toBe('−5 min');
        expect(formatMinutes(0, true)).toBe('0 min');
    });
});

describe('readTimeRow', () => {
    it('reads the estimate and the time spent of a row', () => {
        expect(readTimeRow({ estimate: '60', spent: '90' })).toEqual({ estimate: 60, spent: 90 });
        expect(readTimeRow({ estimate: '', spent: 'x' })).toEqual({ estimate: null, spent: 0 });
        expect(readTimeRow({})).toEqual({ estimate: null, spent: 0 });
    });
});

describe('sumTimes', () => {
    it('has no deviation without estimates', () => {
        expect(sumTimes([])).toEqual({ estimate: null, spent: 0, difference: null, percent: null, status: 'none' });
        expect(sumTimes([{ estimate: null, spent: 30 }]).status).toBe('none');
        expect(sumTimes([{ estimate: 0, spent: 30 }]).difference).toBeNull();
    });

    it('compares the estimate with the time spent on the estimated rows', () => {
        const totals = sumTimes([
            { estimate: 60, spent: 90 },
            { estimate: 60, spent: 30 },
            { estimate: null, spent: 45 },
        ]);
        expect(totals).toEqual({ estimate: 120, spent: 165, difference: 0, percent: 0, status: 'on_track' });
        expect(sumTimes([{ estimate: 60, spent: 75 }])).toMatchObject({ difference: 15, percent: 25, status: 'over' });
    });

    it('formats a deviation', () => {
        expect(formatDeviation(sumTimes([]))).toBe('—');
        expect(formatDeviation(sumTimes([{ estimate: 60, spent: 75 }]))).toBe('+15 min (+25 %)');
        expect(formatDeviation(sumTimes([{ estimate: 60, spent: 30 }]))).toBe('−30 min (-50 %)');
    });
});
