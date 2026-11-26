import AxeBuilder from '@axe-core/playwright';
import { expect, test, type Page } from '@playwright/test';
import { mkdirSync } from 'node:fs';
import { resolve } from 'node:path';

/**
 * Since 3.3.0: a board's Custom fields page — list, add a drop-down with options, edit (rename an option), delete —
 * with axe (WCAG 2.1 A/AA, serious and critical) on each page in light and dark. With FIELD_SHOTS_DIR set it also saves
 * a PNG of each page per theme. The added field is deleted again (the demo's own fields stay).
 */
type Theme = 'light' | 'dark';

const shots = process.env.FIELD_SHOTS_DIR ? resolve(process.env.FIELD_SHOTS_DIR) : null;

async function check(page: Page, name: string): Promise<void> {
  for (const theme of ['light', 'dark'] as Theme[]) {
    await page.evaluate((t) => {
      document.documentElement.setAttribute('data-theme', t);
      document.documentElement.setAttribute('data-bs-theme', t);
    }, theme);
    await page.addStyleTag({ content: '.sf-toolbar,.sf-minitoolbar,[id^="sfwdt"]{display:none!important}' });
    const result = await new AxeBuilder({ page }).include('main').exclude('.sf-toolbar').withTags(['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa']).analyze();
    const serious = result.violations.filter((v) => v.impact === 'serious' || v.impact === 'critical').map((v) => `${name}/${theme} ${v.id}: ${v.nodes.map((n) => n.target.join(' ')).join(', ')}`);
    expect(serious).toEqual([]);
    if (shots) {
      mkdirSync(shots, { recursive: true });
      await page.screenshot({ path: resolve(shots, `fields-${name}-${theme}.png`), fullPage: true });
    }
  }
  await page.evaluate(() => document.documentElement.setAttribute('data-theme', 'light'));
}

test('manage the custom fields of a board', async ({ page }) => {
  const errors: string[] = [];
  page.on('pageerror', (e) => errors.push(e.message));

  await page.goto('/tools/task-board');
  await page.getByRole('link', { name: /Product Backlog/ }).first().click();
  await page.getByRole('link', { name: 'Custom fields' }).first().click();
  await expect(page.getByTestId('nowo-task-fields')).toBeVisible();
  await expect(page.getByTestId('nowo-task-field-row')).toHaveCount(3);
  await check(page, '1-list');

  // Add a drop-down with two options (a colour after a space).
  await page.getByTestId('nowo-task-field-add').first().click();
  await page.getByLabel('Name', { exact: true }).fill('Release train');
  await page.getByLabel('Drop-down').check();
  await page.getByLabel('Options').fill('Spring #22c55e\nAutumn');
  await check(page, '2-new');
  await page.getByRole('button', { name: 'Save' }).click();
  await expect(page.getByText('Field created.')).toBeVisible();

  // Edit: rename an option, add one.
  const row = page.getByTestId('nowo-task-field-row').filter({ hasText: 'Release train' });
  await row.getByTestId('nowo-task-field-edit').click();
  await expect(page.getByTestId('nowo-task-field-option-row')).toHaveCount(2);
  await page.getByLabel('Option 2').fill('Autumn (late)');
  await page.getByLabel('Add options').fill('Winter #3b82f6');
  await check(page, '3-edit');
  await page.getByRole('button', { name: 'Save' }).click();
  await expect(page.getByText('Field saved.')).toBeVisible();
  await expect(page.getByTestId('nowo-task-field-row').filter({ hasText: 'Release train' })).toContainText('Autumn (late)');

  // Delete through the inline confirmation.
  const added = page.getByTestId('nowo-task-field-row').filter({ hasText: 'Release train' });
  await added.getByTestId('nowo-task-field-delete').locator('summary').click();
  await check(page, '4-delete');
  await added.getByTestId('nowo-task-field-delete-confirm').click();
  await expect(page.getByText('Field deleted.')).toBeVisible();
  await expect(page.getByTestId('nowo-task-field-row')).toHaveCount(3);
  expect(errors).toEqual([]);
});
