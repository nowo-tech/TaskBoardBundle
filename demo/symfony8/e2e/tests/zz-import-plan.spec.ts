import AxeBuilder from '@axe-core/playwright';
import { expect, test, type Page } from '@playwright/test';
import { mkdirSync } from 'node:fs';
import { resolve } from 'node:path';

/**
 * Since 3.2.0 (CU-2), named to run last (its boards would come first on the index other specs open): the guided import from ClickUp in the demo (in-memory provider, no network) — every step with
 * the keyboard-reachable no-JS forms, axe (WCAG 2.1 A/AA, serious and critical) on each step's card in light and dark,
 * the confirm dialog, the run and the summary. With PLAN_SHOTS_DIR set it also saves a PNG of each step per theme.
 */
type Theme = 'light' | 'dark';

const shots = process.env.PLAN_SHOTS_DIR ? resolve(process.env.PLAN_SHOTS_DIR) : null;

async function setTheme(page: Page, theme: Theme): Promise<void> {
  await page.evaluate((t) => {
    document.documentElement.setAttribute('data-theme', t);
    document.documentElement.setAttribute('data-bs-theme', t);
  }, theme);
}

async function check(page: Page, name: string): Promise<void> {
  for (const theme of ['light', 'dark'] as Theme[]) {
    await setTheme(page, theme);
    await page.addStyleTag({ content: '.sf-toolbar,.sf-minitoolbar,[id^="sfwdt"]{display:none!important}' });
    const result = await new AxeBuilder({ page }).include('[data-testid^="nowo-task-plan"]').exclude('.sf-toolbar').withTags(['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa']).analyze();
    const serious = result.violations.filter((v) => v.impact === 'serious' || v.impact === 'critical').map((v) => `${name}/${theme} ${v.id}: ${v.nodes.map((n) => n.target.join(' ')).join(', ')}`);
    expect(serious).toEqual([]);
    if (shots) {
      mkdirSync(shots, { recursive: true });
      await page.screenshot({ path: resolve(shots, `after-${name}-${theme}.png`), fullPage: true });
    }
  }
  await setTheme(page, 'light');
}

test('import two lists from ClickUp with the guided import', async ({ page }) => {
  const errors: string[] = [];
  page.on('pageerror', (e) => errors.push(e.message));

  await page.goto('/tools/task-board');
  await page.getByTestId('nowo-task-board-import-plans').click();
  await expect(page.getByTestId('nowo-task-plans')).toBeVisible();
  await check(page, '0-list');
  await page.getByTestId('nowo-task-plan-new').click();

  // Connect.
  await expect(page.getByTestId('nowo-task-plan-privacy')).toBeVisible();
  await check(page, '1-connect');
  await page.getByLabel(/Demo workspace/).first().check();
  await page.getByTestId('nowo-task-plan-next').click();

  // Workspace.
  await page.getByLabel('Acme workspace').check();
  await check(page, '2-workspace');
  await page.getByTestId('nowo-task-plan-next').click();

  // Tree: open the space with the keyboard (a POST answered with the page), tick two lists.
  const open = page.getByRole('button', { name: /Show the contents of Product/ });
  await open.focus();
  await page.keyboard.press('Enter');
  await expect(page.getByLabel(/Bugs/)).toBeVisible();
  await page.getByLabel(/Bugs/).check();
  await page.getByRole('button', { name: /Show the contents of Roadmap/ }).click();
  await page.getByLabel(/Product backlog/).check();
  await check(page, '3-tree');
  await page.getByTestId('nowo-task-plan-next').click();

  // Boards (named so they sort after the demo's own boards, which other specs open first), statuses, fields, people.
  await expect(page.getByTestId('nowo-task-plan-board-row')).toHaveCount(2);
  const names = page.getByRole('textbox', { name: /Name of the new board/ });
  await names.nth(0).fill('Zz imported list 1');
  await names.nth(1).fill('Zz imported list 2');
  await check(page, '4-boards');
  await page.getByTestId('nowo-task-plan-next').click();
  await expect(page.getByTestId('nowo-task-plan-status-table').first()).toBeVisible();
  await check(page, '5-statuses');
  await page.getByTestId('nowo-task-plan-next').click();
  await check(page, '6-fields');
  await page.getByTestId('nowo-task-plan-next').click();
  await expect(page.getByTestId('nowo-task-plan-people-counts')).toBeVisible();
  await check(page, '7-people');
  await page.getByTestId('nowo-task-plan-next').click();

  // Preview and the confirm dialog.
  await expect(page.getByTestId('nowo-task-plan-eta')).toBeVisible();
  await check(page, '8-preview');
  await page.getByTestId('nowo-task-plan-start').click();
  await expect(page.getByRole('dialog')).toBeVisible();
  if (shots) {
    await page.screenshot({ path: resolve(shots, 'after-9-confirm-light.png') });
  }
  await page.getByTestId('nowo-task-plan-start-confirm').click();

  // Run (inline: the import runs in the request) and the summary.
  await expect(page.getByTestId('nowo-task-plan-items')).toBeVisible();
  await expect(page.getByTestId('nowo-task-plan-item').first()).toHaveAttribute('data-status', 'succeeded');
  await check(page, '10-summary');
  expect(errors).toEqual([]);

  // Clean up: delete the plan (its boards stay; the demo's own data is not touched).
  await page.getByTestId('nowo-task-plan-delete').click();
  await page.getByTestId('nowo-task-plan-delete-confirm').click();
  await expect(page.getByTestId('nowo-task-plans')).toBeVisible();
});
