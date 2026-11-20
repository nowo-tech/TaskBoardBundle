import { expect, test, type Page } from '@playwright/test';
import { mkdirSync } from 'node:fs';
import { resolve } from 'node:path';

/** REQ-DEMO-013: README screenshots (captured as PNG, converted to WebP by scripts/to-webp.mjs). */
const outDir = process.env.SCREENSHOT_DIR ? resolve(process.env.SCREENSHOT_DIR) : resolve(__dirname, '../../../../docs/images/demo');

/** The Symfony Web Debug Toolbar is not part of the demo platform: hide it so it never appears in a capture. */
async function settle(page: Page): Promise<void> {
  await page.addStyleTag({ content: '.sf-toolbar,.sf-minitoolbar,[id^="sfwdt"]{display:none!important}' });
  await page.waitForTimeout(300);
}

test.beforeAll(() => {
  mkdirSync(outDir, { recursive: true });
});

test.describe('TaskBoard screenshots', () => {
  test('board-index — the boards list', async ({ page }) => {
    await page.goto('/tools/task-board');
    await expect(page.locator('[data-nowo-ui-page="list"]')).toBeVisible();
    await settle(page);
    await page.screenshot({ path: resolve(outDir, 'board-index.png'), fullPage: true });
  });

  test('board — kanban columns', async ({ page }) => {
    await page.goto('/tools/task-board');
    await page.getByRole('link', { name: /Platform Squad/ }).first().click();
    await expect(page.locator('.nowo-task-board')).toBeVisible();
    await settle(page);
    await page.screenshot({ path: resolve(outDir, 'board.png'), fullPage: true });
  });

  test('board-list — the list view', async ({ page }) => {
    await page.goto('/tools/task-board');
    await page.getByRole('link', { name: /Platform Squad/ }).first().click();
    await expect(page.locator('.nowo-task-board')).toBeVisible();
    await page.goto(`${page.url()}/list`);
    await settle(page);
    await page.screenshot({ path: resolve(outDir, 'board-list.png'), fullPage: true });
  });
});
