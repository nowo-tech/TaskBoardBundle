import { afterEach, describe, expect, it, vi } from 'vitest';
import { readMap, safeStorage, writeEntry } from './storage';

afterEach(() => {
    vi.restoreAllMocks();
    window.localStorage.clear();
});

describe('storage', () => {
    it('reads an empty map when nothing or something odd is saved', () => {
        expect(readMap('k')).toEqual({});
        window.localStorage.setItem('k', '');
        expect(readMap('k')).toEqual({});
        for (const raw of ['[1]', 'null', '3', 'not json']) {
            window.localStorage.setItem('k', raw);
            expect(readMap('k')).toEqual({});
        }
        expect(readMap('k', null)).toEqual({});
    });

    it('writes one entry of the map and keeps the others', () => {
        writeEntry('k', 'a', true);
        writeEntry('k', 'b', 'weeks');
        expect(readMap('k')).toEqual({ a: true, b: 'weeks' });
    });

    it('ignores an empty id, a missing storage and quota errors', () => {
        writeEntry('k', '', true);
        writeEntry('k', 'a', true, null);
        expect(readMap('k')).toEqual({});

        const broken = { getItem: () => null, setItem: () => { throw new Error('quota'); } } as unknown as Storage;
        expect(() => writeEntry('k', 'a', true, broken)).not.toThrow();
    });

    it('gives null when localStorage cannot be opened', () => {
        expect(safeStorage()).toBe(window.localStorage);
        vi.spyOn(window, 'localStorage', 'get').mockImplementation(() => {
            throw new Error('blocked');
        });
        expect(safeStorage()).toBeNull();
        expect(readMap('k')).toEqual({});
    });
});
