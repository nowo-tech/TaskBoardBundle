import { expect, test, type Page } from '@playwright/test';

/**
 * Since 2.8.0 (host mobile UX audit MUX-70, MUX-73, MUX-74) at 390 × 844: the Gantt title column is 96 px and the legend
 * one row, nested subtasks indent by a small step, and the board's column count and "+n" avatar are 12 px at least.
 * Runs in `phone-chromium` and `phone-webkit`; the `desktop` project checks that 1280 px keeps the 2.7.0 sizes.
 */
async function openBoard(page: Page): Promise<string> {
  await page.goto('/tools/task-board');
  await page.getByRole('link', { name: /Platform Squad/ }).first().click();
  await expect(page.locator('.nowo-task-board')).toBeVisible();

  return page.url().replace(/[?#].*$/, '');
}

const isPhone = (page: Page): boolean => (page.viewportSize()?.width ?? 1280) <= 640;

test('gantt: a narrow title column and a one-row legend on a phone, desktop unchanged', async ({ page }) => {
  const board = await openBoard(page);
  await page.goto(`${board}/gantt`);
  const corner = page.locator('.nowo-task-gantt__corner');
  await expect(corner).toBeVisible();
  const width = await corner.evaluate((el) => el.getBoundingClientRect().width);
  const items = page.locator('.nowo-task-gantt__legend li');
  const tops = await items.evaluateAll((els) => [...new Set(els.map((el) => Math.round(el.getBoundingClientRect().top)))]);
  if (isPhone(page)) {
    expect(Math.round(width)).toBe(96);
    expect(tops).toHaveLength(1);
    const { scroll, client } = await page.evaluate(() => ({ scroll: document.documentElement.scrollWidth, client: document.documentElement.clientWidth }));
    expect(scroll).toBeLessThanOrEqual(client);
    for (const selector of ['.nowo-task-gantt__month-label', '.nowo-task-gantt__weekday']) {
      const size = await page.locator(selector).first().evaluate((el) => parseFloat(getComputedStyle(el).fontSize));
      expect(size, selector).toBeGreaterThanOrEqual(12);
    }
  } else {
    expect(Math.round(width)).toBe(288);
  }
});

test('board: the column count and the "+n" avatar are 12 px at least on a phone', async ({ page }) => {
  await openBoard(page);
  const sizes = await page.locator('.nowo-task-board__column-count, .nowo-task-avatar--more').evaluateAll((els) =>
    els.map((el) => parseFloat(getComputedStyle(el).fontSize)));
  expect(sizes.length).toBeGreaterThan(0);
  for (const size of sizes) {
    if (isPhone(page)) {
      expect(size).toBeGreaterThanOrEqual(12);
    } else {
      expect(size).toBeLessThan(12);
    }
  }
});

test('task page: a nested subtask indents by a small step on a phone', async ({ page }) => {
  await openBoard(page);
  const href = await page.locator('.nowo-task-card a[href*="/task/"]').first().getAttribute('href');
  await page.goto(href ?? '/');
  const stamp = `${test.info().project.name} ${Date.now()}`;
  await page.locator('input[name="task_form[title]"].nowo-task-inline-add__input').fill(`Parent ${stamp}`);
  await page.locator('.nowo-task-inline-add__input').press('Enter');
  const parent = page.locator('.nowo-task-subtree [role="treeitem"]', { hasText: `Parent ${stamp}` });
  await expect(parent).toBeVisible();
  const parentUrl = page.url();

  await parent.click();
  await page.waitForLoadState('load');
  await page.locator('input[name="task_form[title]"].nowo-task-inline-add__input').fill(`Child ${stamp}`);
  await page.locator('.nowo-task-inline-add__input').press('Enter');
  await expect(page.locator('.nowo-task-subtree [role="treeitem"]', { hasText: `Child ${stamp}` })).toBeVisible();

  await page.goto(parentUrl);
  const left = (title: string) => page.locator('.nowo-task-subtree__title', { hasText: title }).first()
    .evaluate((el) => el.getBoundingClientRect().left);
  const step = (await left(`Child ${stamp}`)) - (await left(`Parent ${stamp}`));
  expect(step).toBeGreaterThan(0);
  if (isPhone(page)) {
    expect(step).toBeLessThanOrEqual(16);
  } else {
    expect(step).toBeGreaterThanOrEqual(32);
  }
});
