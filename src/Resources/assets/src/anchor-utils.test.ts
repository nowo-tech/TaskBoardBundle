import { describe, expect, it } from 'vitest';
import { sourceAnchor, withAnchor } from './anchor-utils';

describe('sourceAnchor', () => {
    it('turns an import source into a heading slug', () => {
        expect(sourceAnchor('taskboard_json')).toBe('taskboard-json');
        expect(sourceAnchor(' ClickUp_CSV ')).toBe('clickup-csv');
    });

    it('drops values that are empty or not slug-safe', () => {
        expect(sourceAnchor('')).toBe('');
        expect(sourceAnchor('a"b')).toBe('');
        expect(sourceAnchor('x y')).toBe('');
    });
});

describe('withAnchor', () => {
    it('adds or replaces the fragment', () => {
        expect(withAnchor('/manual/import', 'csv')).toBe('/manual/import#csv');
        expect(withAnchor('https://docs.example.test/import?x=1#old', 'json')).toBe('https://docs.example.test/import?x=1#json');
    });

    it('removes the fragment for an empty anchor', () => {
        expect(withAnchor('/manual/import#old', '')).toBe('/manual/import');
        expect(withAnchor('/manual/import', '')).toBe('/manual/import');
    });
});
