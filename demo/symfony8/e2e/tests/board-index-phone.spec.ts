import { expect, test, type Locator, type Page } from '@playwright/test';

/**
 * Since 2.0.1 (audit HY-A): on a phone the board index is a card, and a long description must not widen it: UiKit's absolute
 * label then lies over the value and takes the tap. Every point across the board link and the view buttons must reach them.
 * Runs in `phone` (Pixel 7), `iphone` (iPhone 13) and `firefox-narrow`; wider windows have no cards.
 */
test.skip(({ viewport }) => (viewport?.width ?? 1280) > 640, 'Cards only.');

async function deadPoints(page: Page, target: Locator): Promise<string[]> {
  await target.scrollIntoViewIfNeeded();

  return target.evaluate((el) => {
    const box = el.getBoundingClientRect();
    const dead: string[] = [];
    for (let i = 0; i <= 10; i += 1) {
      const x = box.left + (box.width * i) / 10 + (i === 0 ? 1 : i === 10 ? -1 : 0);
      const hit = document.elementFromPoint(x, box.top + box.height / 2);
      if (hit === null || !el.contains(hit)) {
        dead.push(`${i}/10 hit ${hit?.tagName ?? 'nothing'} (${Math.round(x)} of ${window.innerWidth})`);
      }
    }

    return dead;
  });
}

test('every point of the board link and the view buttons takes the tap', async ({ page }) => {
  await page.goto('/tools/task-board');
  const name = page.locator('a.nowo-task-board-list__name').first();
  await expect(name).toBeVisible();
  expect(await deadPoints(page, name)).toEqual([]);

  const views = page.locator('.nowo-task-board-list__views').first().locator('a');
  const count = await views.count();
  expect(count).toBeGreaterThan(0);
  for (let i = 0; i < count; i += 1) {
    expect(await deadPoints(page, views.nth(i))).toEqual([]);
  }

  const { scroll, client } = await page.evaluate(() => ({ scroll: document.documentElement.scrollWidth, client: document.documentElement.clientWidth }));
  expect(scroll).toBeLessThanOrEqual(client);
});

test('a tap in the middle of the board link opens the board', async ({ page }) => {
  await page.goto('/tools/task-board');
  await page.locator('a.nowo-task-board-list__name').first().click();
  await expect(page.locator('.nowo-task-board')).toBeVisible();
});
