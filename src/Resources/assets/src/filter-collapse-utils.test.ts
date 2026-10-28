import { describe, expect, it } from 'vitest';
import { countActiveFilters, resolveOpen } from './filter-collapse-utils';

const field = (type: string, value = '', checked = false, ignored = false) => ({ type, value, checked, ignored });

describe('countActiveFilters', () => {
    it('counts filled fields and checked boxes', () => {
        expect(countActiveFilters([field('search', ' bug '), field('select', 'high'), field('checkbox', '1', true)])).toBe(3);
    });

    it('skips empty, hidden, button and ignored fields', () => {
        expect(countActiveFilters([
            field('search', '  '),
            field('select'),
            field('checkbox', '1', false),
            field('radio', '1', false),
            field('hidden', 'x'),
            field('submit', 'Go'),
            field('button', 'x'),
            field('checkbox', '1', true, true),
        ])).toBe(0);
    });
});

describe('resolveOpen', () => {
    it('opens while a filter is active, else follows the saved state', () => {
        expect(resolveOpen(false, 1)).toBe(true);
        expect(resolveOpen(false, 0)).toBe(false);
        expect(resolveOpen(true, 0)).toBe(true);
        expect(resolveOpen(undefined, 0)).toBe(true);
    });
});
