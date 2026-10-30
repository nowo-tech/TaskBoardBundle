import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';
import { describe, expect, it } from 'vitest';

/**
 * 1.16.1 stylesheet regressions found by a host UX audit (rules a jsdom render cannot measure).
 */
const css = readFileSync(resolve(process.cwd(), 'src/Resources/public/css/task-board.css'), 'utf8');

/** Every declaration block of `selector` (exact selector text), joined. */
function rules(selector: string): string {
  const escaped = selector.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
  const matches = [...css.matchAll(new RegExp(`(?:^|\\n)\\s*${escaped}\\s*\\{([^}]*)\\}`, 'g'))];

  return matches.map((match) => match[1]).join('\n');
}

describe('task-board.css', () => {
  it('keeps the history card content off the card edges (1.19.1)', () => {
    expect(rules('.nowo-task-detail__history > :not(summary)')).toMatch(/margin-inline:\s*1\.25rem/);
    expect(rules('.nowo-task-detail__history > :last-child')).toMatch(/margin-bottom:\s*1rem/);
  });

  it('keeps the task attributes compact: rows at their fields\' height, one line below the grid (1.18.1)', () => {
    expect(rules('.nowo-task-detail__attr')).toMatch(/min-height:\s*2\.25rem/);
    expect(rules('.nowo-task-detail__attrs > .nowo-task-detail__attr:last-child')).toMatch(/border-bottom:\s*0/);
    expect(rules('.nowo-task-detail__meta')).toMatch(/padding-top:\s*0/);
  });

  it('sizes the Gantt grid to its timeline so the sticky title column stays in view', () => {
    expect(rules('.nowo-task-gantt__grid')).toMatch(/width:\s*max-content/);
  });

  it('dims a completed Gantt title through its text, never the sticky cell opacity', () => {
    expect(rules('.nowo-task-gantt__label-cell.is-completed')).not.toMatch(/opacity/);
  });

  it('styles list filter labels without specificity, so host checkbox labels keep their layout', () => {
    expect(rules(':where(.nowo-task-board-list-filters) label')).toMatch(/flex-direction:\s*column/);
    // A host label that is a flex row (display wins over `inline`) must not inherit the stacked direction.
    expect(rules(':where(.nowo-task-board-list-filters .nowo-task-check) label')).toMatch(/display:\s*inline;\s*flex-direction:\s*row;/);
    expect(css).not.toMatch(/\n\.nowo-task-board-list-filters (?:\.nowo-task-check )?label\s*[,{]/);
  });

  it('stacks the task attributes in a narrow detail', () => {
    expect(rules('.nowo-task-detail__attrs')).toMatch(/container:\s*nowo-task-attrs \/ inline-size/);
    expect(css).toMatch(/@container nowo-task-attrs \(max-width: 30rem\)\s*\{\s*\.nowo-task-detail__attr\s*\{\s*grid-template-columns:\s*minmax\(0, 1fr\)/);
  });

  it('keeps a deviation duration on one line', () => {
    expect(rules('.nowo-task-deviation__table td')).toMatch(/white-space:\s*nowrap/);
  });

  it('gives phone tap targets at least 32px', () => {
    const phone = css.slice(css.lastIndexOf('@media (max-width: 640px)'));
    for (const selector of ['.nowo-task-board__column-edit', '.nowo-task-dialog__close']) {
      expect(phone).toMatch(new RegExp(`\\${selector}\\s*\\{\\s*min-width:\\s*2rem;\\s*min-height:\\s*2rem;`));
    }
    expect(phone).toMatch(/\.nowo-task-detail \.tagify__tag__removeBtn\s*\{\s*width:\s*2rem;\s*height:\s*2rem;/);
    expect(rules('.nowo-task-dialog--native .nowo-task-dialog__actions .btn')).toMatch(/min-height:\s*2rem/);
  });

  it('prints the list group name in the text colour, the column colour as a dot', () => {
    const name = rules('.nowo-task-list__group-name');
    expect(name).toMatch(/color:\s*var\(--nowo-task-ink/);
    expect(name).not.toMatch(/(?:^|\s|;)color:\s*var\(--nowo-task-column-color\)/);
    expect(rules('.nowo-task-list__group-name::before')).toMatch(/background:\s*var\(--nowo-task-column-color\)/);
  });
});
