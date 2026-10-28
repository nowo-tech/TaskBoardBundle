import { describe, expect, it } from 'vitest';
import { compareRows, readSortRow } from './list-sort-utils';

const row = (title: string, priority: string, due: string, column: string, position: number) => ({ title, priority, due, column, position });

describe('compareRows', () => {
    const a = row('Beta 2', 'low', '', 'Done', 0);
    const b = row('beta 10', 'urgent', '2026-11-01', 'Doing', 1);
    const c = row('Alpha', 'odd', '2026-10-01', 'doing', 2);

    it('sorts by title, priority, due date and column', () => {
        expect([a, b, c].sort((x, y) => compareRows(x, y, 'title')).map((r) => r.title)).toEqual(['Alpha', 'Beta 2', 'beta 10']);
        expect([a, b, c].sort((x, y) => compareRows(x, y, 'priority')).map((r) => r.position)).toEqual([1, 0, 2]);
        expect([a, b, c].sort((x, y) => compareRows(x, y, 'due')).map((r) => r.position)).toEqual([2, 1, 0]);
        expect([a, b, c].sort((x, y) => compareRows(x, y, 'column')).map((r) => r.position)).toEqual([1, 2, 0]);
    });

    it('keeps the board order for equal rows and an unknown key', () => {
        expect(compareRows(row('x', 'odd', '', 'a', 3), row('x', 'odd2', '', 'a', 1), 'priority')).toBe(2);
        expect(compareRows(row('x', 'low', '', 'a', 0), row('x', 'odd', '', 'a', 1), 'priority')).toBeLessThan(0);
        expect(compareRows(row('x', 'low', '', 'a', 3), row('y', 'low', '', 'b', 1), '')).toBe(2);
        expect(compareRows(row('x', 'low', '2026-01-01', 'a', 0), row('y', 'low', '2026-01-01', 'b', 1), 'due')).toBe(-1);
        expect(compareRows(row('x', 'low', '2026-01-01', 'a', 0), row('y', 'low', '', 'b', 1), 'due')).toBe(-1);
    });
});

describe('readSortRow', () => {
    it('reads the row data with defaults', () => {
        expect(readSortRow({ title: 'T', priority: 'high', due: '2026-10-01', column: 'Doing' }, 4)).toEqual(row('T', 'high', '2026-10-01', 'Doing', 4));
        expect(readSortRow({}, 0)).toEqual(row('', '', '', '', 0));
    });
});
