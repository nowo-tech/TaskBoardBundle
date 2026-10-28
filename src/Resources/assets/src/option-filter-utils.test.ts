import { describe, expect, it } from 'vitest';
import { matchesOption, normalize } from './option-filter-utils';

describe('matchesOption', () => {
    it('ignores case and accents', () => {
        expect(normalize('  Revisión ')).toBe('revision');
        expect(matchesOption('Revisión del diseño', 'revision')).toBe(true);
    });

    it('needs every word and matches everything for an empty query', () => {
        expect(matchesOption('Deploy the API', 'api deploy')).toBe(true);
        expect(matchesOption('Deploy the API', 'api docs')).toBe(false);
        expect(matchesOption('Anything', '   ')).toBe(true);
    });
});
