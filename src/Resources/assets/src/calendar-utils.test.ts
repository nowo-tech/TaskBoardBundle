import { describe, expect, it } from 'vitest';
import { addDays, dayDiff, dragEdge, eventDropDay, eventMoveFields, grabOffset, gridStep, movedDates, movedEvent, phoneListUrl, readEventMove, sameDates, shiftDates, snapDates, snapToWorkingDay } from './calendar-utils';

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

describe('calendar events (2.24.0)', () => {
    const event = { source: 'meetings', id: 'm-1', first: '2030-03-04', start: '2030-03-04T09:30:00+01:00', end: '2030-03-04T10:00:00+01:00', allDay: false, version: 'v3' };

    it('reads a movable event from its dataset', () => {
        const el = document.createElement('li');
        el.dataset.eventSource = 'meetings';
        el.dataset.eventId = 'm-1';
        el.dataset.first = '2030-03-04';
        el.dataset.start = event.start;
        el.dataset.end = event.end;
        el.dataset.allDay = '0';
        el.dataset.version = 'v3';
        expect(readEventMove(el.dataset)).toEqual(event);
        delete el.dataset.version;
        el.dataset.allDay = '1';
        expect(readEventMove(el.dataset)).toEqual({ ...event, allDay: true, version: '' });
        for (const key of ['eventSource', 'eventId', 'first', 'start', 'end']) {
            const copy = { ...el.dataset, version: 'v3' } as DOMStringMap;
            delete copy[key];
            expect(readEventMove(copy)).toBeNull();
        }
    });

    it('moves the first day by the days the pointer moved, and not at all on the same day', () => {
        expect(eventDropDay('2030-03-04', '2030-03-05', '2030-03-08')).toBe('2030-03-07');
        expect(eventDropDay('2030-03-31', '2030-03-31', '2030-03-30')).toBe('2030-03-30');
        expect(eventDropDay('2030-03-04', '2030-03-05', '2030-03-05')).toBeNull();
    });

    it('builds the reschedule body', () => {
        expect(eventMoveFields(event, '2030-03-06', 'tok')).toEqual({
            source: 'meetings', id: 'm-1', date: '2030-03-06', expected_start: event.start, expected_end: event.end, all_day: '0', version: 'v3', _token: 'tok',
        });
        expect(eventMoveFields({ ...event, allDay: true }, '2030-03-06', 'tok').all_day).toBe('1');
    });

    it('keeps the answer of a move for Undo', () => {
        expect(movedEvent(event, '2030-03-06', { start: 'S', end: 'E', version: 'v4' })).toEqual({ ...event, first: '2030-03-06', start: 'S', end: 'E', version: 'v4' });
        expect(movedEvent(event, '2030-03-06', { version: null })).toEqual({ ...event, first: '2030-03-06', version: '' });
    });
});
