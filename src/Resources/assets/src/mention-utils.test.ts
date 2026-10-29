import { describe, expect, it } from 'vitest';
import { filterCandidates, findMentionQuery, insertMention, keptMentions, moveActive, parsePicked } from './mention-utils';

const ana = { id: '1', label: 'Ana Ruiz' };
const bob = { id: '2', label: 'Bob' };
const zoe = { id: '3', label: 'Zoë Ánderson' };

describe('findMentionQuery', () => {
    it('finds the "@…" typed before the caret', () => {
        expect(findMentionQuery('Hi @an', 6)).toEqual({ start: 3, query: 'an' });
        expect(findMentionQuery('@', 1)).toEqual({ start: 0, query: '' });
        expect(findMentionQuery('line\n@b', 7)).toEqual({ start: 5, query: 'b' });
    });

    it('ignores e-mails, spaces after the name, long words and text without "@"', () => {
        expect(findMentionQuery('mail a@b', 8)).toBeNull();
        expect(findMentionQuery('@ana here', 9)).toBeNull();
        expect(findMentionQuery(`@${'x'.repeat(41)}`, 42)).toBeNull();
        expect(findMentionQuery('nothing', 7)).toBeNull();
    });
});

describe('filterCandidates', () => {
    it('matches anywhere, accents and case ignored, names starting with the query first', () => {
        expect(filterCandidates([zoe, ana, bob], 'an').map((c) => c.id)).toEqual(['1', '3']);
        expect(filterCandidates([zoe, ana, bob], 'ZOE').map((c) => c.id)).toEqual(['3']);
        expect(filterCandidates([bob, ana], '').map((c) => c.id)).toEqual(['1', '2']);
        expect(filterCandidates([ana, bob, zoe], '', 2)).toHaveLength(2);
        expect(filterCandidates([{ id: '9', label: 'Ann' }, ana], 'an').map((c) => c.id)).toEqual(['1', '9']);
    });
});

describe('insertMention and keptMentions', () => {
    it('writes "@Label " in place of the query', () => {
        expect(insertMention('Hi @an!', 3, 6, 'Ana Ruiz')).toEqual({ text: 'Hi @Ana Ruiz !', caret: 13 });
    });

    it('keeps the picked people still named in the text, once', () => {
        expect(keptMentions('@Ana Ruiz and @Ana Ruiz', [ana, ana, bob])).toEqual([ana]);
    });
});

describe('moveActive', () => {
    it('wraps around and starts at either end', () => {
        expect(moveActive(0, 0, 1)).toBe(-1);
        expect(moveActive(-1, 3, 1)).toBe(0);
        expect(moveActive(-1, 3, -1)).toBe(2);
        expect(moveActive(2, 3, 1)).toBe(0);
        expect(moveActive(0, 3, -1)).toBe(2);
    });
});

describe('parsePicked', () => {
    it('reads the hidden field, ignoring anything odd', () => {
        expect(parsePicked('[{"id":"1","label":"Ana Ruiz"},{"id":2},{"label":"x"},3]')).toEqual([ana]);
        expect(parsePicked('{"id":"1"}')).toEqual([]);
        expect(parsePicked('not json')).toEqual([]);
        expect(parsePicked('')).toEqual([]);
    });
});
