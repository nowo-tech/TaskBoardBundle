import AxeBuilder from '@axe-core/playwright';
import { expect, test, type Page } from '@playwright/test';

/**
 * Since 1.23: member pictures over the initial chips (demo resolver: the demo user and Ada have a picture, Grace's URL
 * is broken on purpose). axe (WCAG 2.1 A/AA) must report no image-alt, role-img-alt, aria-allowed-role or
 * color-contrast problem on the member chips, with a picture shown and with one that fell back to the initial
 * (other contrast issues of the board, such as priority and status badges, are pre-existing and tracked apart).
 */
const RULES = ['image-alt', 'role-img-alt', 'aria-allowed-role', 'color-contrast'];

async function axe(page: Page): Promise<string[]> {
  const result = await new AxeBuilder({ page }).exclude('.sf-toolbar').withTags(['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa']).analyze();

  return result.violations
    .filter((v) => RULES.includes(v.id) || v.impact === 'critical')
    .map((v) => ({ id: v.id, targets: v.nodes.map((n) => n.target.join(' ')).filter((t) => v.id !== 'color-contrast' || /avatar/.test(t)) }))
    .filter((v) => v.targets.length > 0)
    .map((v) => `${v.id}: ${v.targets.join(', ')}`);
}

async function openBoard(page: Page): Promise<void> {
  await page.goto('/tools/task-board');
  await page.getByRole('link', { name: /Platform Squad/ }).first().click();
}

test('pictures on cards, the list and the task page, a broken one falls back', async ({ page }) => {
  const errors: string[] = [];
  page.on('pageerror', (e) => errors.push(e.message));

  await openBoard(page);
  const card = page.locator('.nowo-task-avatars').filter({ has: page.locator('img[src="/avatars/ada.png"]') }).first();
  const ada = card.locator('img[src="/avatars/ada.png"]');
  await expect(ada).toHaveAttribute('alt', '');
  await expect.poll(() => ada.evaluate((img: HTMLImageElement) => img.naturalWidth)).toBeGreaterThan(0);
  await expect(card.locator('[role="img"][aria-label="ada@example.com"]')).toHaveCount(1);
  expect(await axe(page)).toEqual([]);

  await page.goto(page.url() + '/list');
  await expect(page.locator('img[src="/avatars/ada.png"]').first()).toBeVisible();
  expect(await axe(page)).toEqual([]);

  // The task page shows Grace too: her URL is broken, so her chip is back to the initial (no image, no inline handler).
  await page.getByRole('link', { name: 'Implement task board API' }).first().click();
  const chips = page.locator('.nowo-task-detail__member-chip-avatar');
  await expect(chips.filter({ hasText: 'G' })).toHaveCount(1);
  // (The closed members dialog keeps its lazy picture until it is opened: only the visible chips are checked.)
  await expect(page.locator('.nowo-task-detail__member-chip-avatar img[src="/avatars/missing.png"]')).toHaveCount(0);
  await expect(chips.filter({ hasText: 'G' })).not.toHaveClass(/has-image/);
  expect(await page.locator('[onerror]').count()).toBe(0);
  expect(await axe(page)).toEqual([]);

  expect(errors).toEqual([]);
});
