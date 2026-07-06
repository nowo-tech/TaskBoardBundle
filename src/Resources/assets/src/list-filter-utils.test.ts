import { describe, expect, it } from 'vitest';
import { matchesListFilter } from './list-filter-utils';

describe('matchesListFilter', () => {
    it('matches when all filters are empty', () => {
        expect(matchesListFilter('Fix bug', 'high', 'To do', '', '', '')).toBe(true);
    });

    it('filters by search text', () => {
        expect(matchesListFilter('Fix bug', 'high', 'To do', 'auth', '', '')).toBe(false);
        expect(matchesListFilter('Auth middleware', 'high', 'To do', 'auth', '', '')).toBe(true);
    });

    it('filters by priority and column', () => {
        expect(matchesListFilter('Task', 'low', 'Done', '', 'high', '')).toBe(false);
        expect(matchesListFilter('Task', 'high', 'Done', '', 'high', 'To do')).toBe(false);
        expect(matchesListFilter('Task', 'high', 'To do', '', 'high', 'To do')).toBe(true);
    });
});
