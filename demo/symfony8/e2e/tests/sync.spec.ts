import AxeBuilder from '@axe-core/playwright';
import { expect, test, type Page } from '@playwright/test';

/**
 * Since 1.23: the provider connection flow of the demo (an in-memory "ClickUp", no network) — connect, browse the
 * tree with the keyboard, import, refresh, unlink — with axe (WCAG 2.1 A/AA, serious and critical) on the connection
 * UI of each page (sync regions, badge, dialogs; the board's cards and the shared flash alerts have pre-existing
 * contrast issues, tracked apart).
 * Desktop and phone run one after the other on the same demo board (each test unlinks at the end).
 */
async function axe(page: Page): Promise<string[]> {
  const result = await new AxeBuilder({ page }).include('[data-testid^="nowo-task-sync"]').include('[role="dialog"]').exclude('.sf-toolbar').withTags(['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa']).analyze();

  return result.violations
    .filter((v) => v.impact === 'serious' || v.impact === 'critical')
    .map((v) => `${v.id}: ${v.nodes.map((n) => n.target.join(' ')).join(', ')}`);
}

async function openFirstBoard(page: Page): Promise<string> {
  await page.goto('/tools/task-board');
  await page.locator('a[href*="/tools/task-board/board/"]').first().click();
  await expect(page).toHaveURL(/\/tools\/task-board\/board\/[^/?#]+$/);

  return page.url();
}

test('connect a list, refresh it and unlink it', async ({ page }) => {
  const errors: string[] = [];
  page.on('pageerror', (e) => errors.push(e.message));

  const boardUrl = await openFirstBoard(page);
  await page.getByTestId('nowo-task-sync-connect-link').click();
  await expect(page.getByTestId('nowo-task-sync-connect')).toBeVisible();
  expect(await axe(page)).toEqual([]);

  // Browse with the keyboard: Enter opens a level (fetched on first open, announced in the live region).
  const workspace = page.getByRole('button', { name: /Acme workspace/ });
  await workspace.focus();
  await page.keyboard.press('Enter');
  await expect(workspace).toHaveAttribute('aria-expanded', 'true');
  const space = page.getByRole('button', { name: /^Product/ });
  await space.click();
  await expect(space).toHaveAttribute('aria-expanded', 'true');
  await page.getByRole('button', { name: /Roadmap/ }).click();
  await expect(page.locator('[data-nowo-task-board-container-browser-target="status"]')).toContainText('Roadmap');
  await page.getByRole('radio', { name: /Product backlog — 4 tasks/ }).check();
  expect(await axe(page)).toEqual([]);

  await page.getByTestId('nowo-task-sync-import').click();
  await expect(page.getByTestId('nowo-task-sync-linked')).toBeVisible();
  // The first project imports the 4 tasks; the next one (same demo board, tasks kept on unlink) links them again.
  await expect(page.getByTestId('nowo-task-sync-history')).toContainText(/0 conflicts/);
  await expect(page.getByTestId('nowo-task-sync-history')).toContainText(/4 created|4 unchanged/);
  expect(await axe(page)).toEqual([]);

  await page.goto(boardUrl);
  await expect(page.getByTestId('nowo-task-sync-badge')).toContainText('ClickUp · Product backlog');
  await expect(page.getByText('Build the signup API').first()).toBeVisible();
  expect(await axe(page)).toEqual([]);

  // Refresh from the header: nothing changed remotely.
  await page.getByTestId('nowo-task-sync-header-refresh').click();
  await expect(page.getByTestId('nowo-task-sync-history')).toContainText('0 created, 0 updated');
  await expect(page.getByTestId('nowo-task-sync-history').locator('tbody tr')).toHaveCount(2);

  // Unlink behind its dialog: the tasks stay.
  await page.getByTestId('nowo-task-sync-unlink').click();
  const dialog = page.getByRole('dialog', { name: 'Unlink this board?' });
  await expect(dialog).toBeVisible();
  expect(await axe(page)).toEqual([]);
  await dialog.getByTestId('nowo-task-sync-unlink-confirm').click();
  await expect(page).toHaveURL(boardUrl);
  await expect(page.getByTestId('nowo-task-sync-connect-link')).toBeVisible();
  await expect(page.getByText('Build the signup API').first()).toBeVisible();

  expect(errors).toEqual([]);
});

test('the container browser works without script', async ({ browser }) => {
  const context = await browser.newContext({ javaScriptEnabled: false });
  const page = await context.newPage();
  await openFirstBoard(page);
  await page.getByTestId('nowo-task-sync-connect-link').click();
  await page.getByRole('button', { name: /Acme workspace/ }).click();
  await expect(page.getByRole('button', { name: /Acme workspace/ })).toHaveAttribute('aria-expanded', 'true');
  await expect(page.getByRole('button', { name: /^Product/ })).toBeVisible();
  await context.close();
});
