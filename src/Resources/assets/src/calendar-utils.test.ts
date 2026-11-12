import { describe, expect, it } from 'vitest';
import { addDays, dayDiff, dragEdge, grabOffset, gridStep, movedDates, phoneListUrl, sameDates, shiftDates, snapDates, snapToWorkingDay } from './calendar-utils';

describe('calendar date maths', () => {
    it('counts days across months, years and a daylight-saving change', () => {
        expect(dayDiff('2026-11-01', '2026-11-08')).toBe(7);
        expect(dayDiff('2026-12-31', '2027-01-01')).toBe(1);
        expect(dayDiff('2026-03-28', '2026-03-30')).toBe(2);
        expect(dayDiff('2026-11-08', '2026-11-01')).toBe(-7);
        expect(addDays('2026-03-28', 2)).toBe('2026-03-30');
        expect(addDays('2028-02-28', 1)).toBe('2028-02-29');
    });

    it('moves both dates by the same days, and leaves a missing date missing', () => {
        expect(shiftDates({ start: '2026-11-03', due: '2026-11-05' }, 3)).toEqual({ start: '2026-11-06', due: '2026-11-08' });
        expect(shiftDates({ start: '', due: '2026-11-05' }, -1)).toEqual({ start: '', due: '2026-11-04' });
        expect(shiftDates({ start: '2026-11-05', due: '' }, 1)).toEqual({ start: '2026-11-06', due: '' });
    });

    it('moves one edge, and stops it at the other date', () => {
        const dates = { start: '2026-11-03', due: '2026-11-05' };
        expect(dragEdge(dates, 'due', '2026-11-09')).toEqual({ start: '2026-11-03', due: '2026-11-09' });
        expect(dragEdge(dates, 'due', '2026-11-01')).toEqual({ start: '2026-11-03', due: '2026-11-03' });
        expect(dragEdge(dates, 'start', '2026-11-01')).toEqual({ start: '2026-11-01', due: '2026-11-05' });
        expect(dragEdge(dates, 'start', '2026-11-09')).toEqual({ start: '2026-11-05', due: '2026-11-05' });
        expect(sameDates(dragEdge(dates, 'due', '2026-11-05'), dates)).toBe(true);
    });

    it('finds the day of a bar the pointer holds', () => {
        expect(grabOffset(10, 0, 300, 3)).toBe(0);
        expect(grabOffset(150, 0, 300, 3)).toBe(1);
        expect(grabOffset(299, 0, 300, 3)).toBe(2);
        expect(grabOffset(999, 0, 300, 3)).toBe(2);
        expect(grabOffset(-5, 0, 300, 3)).toBe(0);
        expect(grabOffset(50, 0, 100, 1)).toBe(0);
        expect(grabOffset(50, 0, 0, 3)).toBe(0);
    });

    it('moves through a grid of seven columns and never out of it', () => {
        expect(gridStep('ArrowRight', 0, 14)).toBe(1);
        expect(gridStep('ArrowLeft', 0, 14)).toBeNull();
        expect(gridStep('ArrowDown', 3, 14)).toBe(10);
        expect(gridStep('ArrowDown', 10, 14)).toBeNull();
        expect(gridStep('ArrowUp', 10, 14)).toBe(3);
        expect(gridStep('Home', 10, 14)).toBe(7);
        expect(gridStep('End', 8, 14)).toBe(13);
        expect(gridStep('Enter', 8, 14)).toBeNull();
    });

    it('opens the list on a phone only when no mode was asked for', () => {
        expect(phoneListUrl('https://h.test/c?date=2026-11-01', true)).toBe('https://h.test/c?date=2026-11-01&mode=list');
        expect(phoneListUrl('https://h.test/c?mode=month', true)).toBeNull();
        expect(phoneListUrl('https://h.test/c', false)).toBeNull();
    });

    it('snaps a drop to the next working day (calendar.skip_non_working_days)', () => {
        const off = new Set(['2026-11-14', '2026-11-15', '2026-11-16']);
        expect(snapToWorkingDay('2026-11-13', off)).toBe('2026-11-13');
        expect(snapToWorkingDay('2026-11-14', off)).toBe('2026-11-17');
        expect(snapToWorkingDay('2026-11-14', off, 2)).toBe('2026-11-14');
        expect(snapToWorkingDay('2026-11-14', new Set())).toBe('2026-11-14');
        expect(snapDates({ start: '2026-11-14', due: '2026-11-15' }, off)).toEqual({ start: '2026-11-17', due: '2026-11-18' });
        expect(snapDates({ start: '2026-11-12', due: '2026-11-14' }, off)).toEqual({ start: '2026-11-12', due: '2026-11-17' });
        expect(snapDates({ start: '', due: '2026-11-15' }, off)).toEqual({ start: '', due: '2026-11-17' });
        expect(snapDates({ start: '2026-11-15', due: '' }, off)).toEqual({ start: '2026-11-17', due: '' });
        expect(snapDates({ start: '', due: '' }, off)).toEqual({ start: '', due: '' });
        expect(snapDates({ start: '2026-11-14', due: '2026-11-15' }, new Set())).toEqual({ start: '2026-11-14', due: '2026-11-15' });
    });
});

describe('movedDates (2.14.1, audit NN-L2)', () => {
    const weekend = new Set(['2030-03-09', '2030-03-10']);

    it('writes a move that changes the dates', () => {
        expect(movedDates({ start: '2030-03-04', due: '2030-03-05' }, 1, new Set())).toEqual({ start: '2030-03-05', due: '2030-03-06' });
        expect(movedDates({ start: '2030-03-07', due: '2030-03-07' }, 2, weekend)).toEqual({ start: '2030-03-11', due: '2030-03-11' });
    });

    it('writes nothing when the move snaps back onto the same dates', () => {
        // Friday 8 March dropped on Saturday 9: the next working day is Monday 11, not back. Monday 11 dropped on Sunday 10
        // snaps forward to Monday 11 again.
        expect(movedDates({ start: '2030-03-11', due: '2030-03-11' }, -1, weekend)).toBeNull();
        expect(movedDates({ start: '', due: '2030-03-11' }, -2, weekend)).toBeNull();
        expect(movedDates({ start: '2030-03-04', due: '2030-03-04' }, 0, new Set())).toBeNull();
    });
});
