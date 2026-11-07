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
  it('gives the connection badge a text colour with AA contrast (1.23)', () => {
    expect(rules('.nowo-task-sync-badge .nowo-task-sync-badge__label')).toMatch(/color:\s*var\(--nowo-task-sync-badge-color,\s*#1c5d99\)\s*!important/);
  });

  it('gives tags a text colour with AA contrast over the framework utility (1.23)', () => {
    expect(rules('.nowo-task-tags .nowo-task-tag')).toMatch(/color:\s*var\(--nowo-task-accent,\s*#7e22ce\)\s*!important/);
  });

  it('keeps the history card content off the card edges (1.19.1)', () => {
    expect(rules('.nowo-task-detail__history > :not(summary)')).toMatch(/margin-inline:\s*1\.25rem/);
    expect(rules('.nowo-task-detail__history > :last-child')).toMatch(/margin-bottom:\s*1rem/);
  });

  it('keeps the task attributes compact: rows at their fields\' height, one line below the grid (1.18.1)', () => {
    expect(rules('.nowo-task-detail__attr')).toMatch(/min-height:\s*2\.25rem/);
    expect(rules('.nowo-task-detail__attrs > .nowo-task-detail__attr:last-child')).toMatch(/border-bottom:\s*0/);
    expect(rules('.nowo-task-detail__meta')).toMatch(/padding-top:\s*0/);
  });

  it('ends the Views buttons in an end-aligned table cell only, and keeps them at the start in a card (2.1.1)', () => {
    expect(rules('.nowo-ui-cell--end > .nowo-task-board-list__views')).toMatch(/justify-content:\s*flex-end/);
    // No general rule: a host's own list (_board_list_item) has no such cell and stays as it was.
    expect(rules('.nowo-task-board-list__views')).not.toMatch(/justify-content/);
    // Inside the card container query; its selector must outweigh the rule above.
    expect(rules('.nowo-ui-table--cards > tbody > tr > td > .nowo-task-board-list__views')).toMatch(/justify-content:\s*flex-start/);
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

  it('keeps the cells of a board inside their card, so the kit label never lies over the value (2.0.1)', () => {
    const card = css.slice(css.indexOf('@container nowo-ui-table (max-width: 39.99rem)'));
    expect(card).toMatch(/td:has\([^)]*nowo-task-board-list__name[^)]*\)\s*\{\s*box-sizing:\s*border-box/);
    expect(card).toMatch(/\.nowo-task-board-list__description\s*\{[^}]*white-space:\s*normal/);
  });

  it('keeps a deviation duration on one line', () => {
    expect(rules('.nowo-task-deviation__table td')).toMatch(/white-space:\s*nowrap/);
  });

  it('gives phone tap targets at least 32px', () => {
    const phone = css.slice(css.lastIndexOf('@media (max-width: 640px)'));
    for (const selector of ['.nowo-task-board__column-edit', '.nowo-ui-dialog__close']) {
      expect(phone).toMatch(new RegExp(`\\${selector}\\s*\\{\\s*min-width:\\s*2rem;\\s*min-height:\\s*2rem;`));
    }
    expect(phone).toMatch(/\.nowo-task-detail \.tagify__tag__removeBtn\s*\{\s*width:\s*2rem;\s*height:\s*2rem;/);
    expect(rules('.nowo-task-dialog--native .nowo-task-dialog__actions .nowo-ui-btn')).toMatch(/min-height:\s*2rem/);
  });

  it('keeps the backdrop tokens hosts set (2.0)', () => {
    const backdrop = rules('dialog.nowo-task-dialog--native::backdrop');
    expect(backdrop).toMatch(/--nowo-task-overlay,/);
    expect(backdrop).toMatch(/--nowo-task-overlay-blur,/);
  });

  it('leaves the dialog shell to the kit (2.0)', () => {
    expect(rules('dialog.nowo-task-dialog--native')).toBe('');
    expect(rules('.nowo-task-dialog--native .nowo-task-dialog__body')).not.toMatch(/overflow|padding/);
    expect(css).not.toMatch(/--tblr-/);
  });

  it('prints the list group name in the text colour, the column colour as a dot', () => {
    const name = rules('.nowo-task-list__group-name');
    expect(name).toMatch(/color:\s*var\(--nowo-task-ink/);
    expect(name).not.toMatch(/(?:^|\s|;)color:\s*var\(--nowo-task-column-color\)/);
    expect(rules('.nowo-task-list__group-name::before')).toMatch(/background:\s*var\(--nowo-task-column-color\)/);
  });
});
