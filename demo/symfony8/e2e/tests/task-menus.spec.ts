import { expect, test, type CDPSession, type Locator, type Page } from '@playwright/test';

/**
 * Since 2.22.0 (owner request via Beacon): the task and column menus with UiKit's context menus (the demo turns on
 * `nowo_ui_kit.theme.context_menus`), the focus mode toggle (`theme.focus_mode.enabled`, the demo shell is the focus mode
 * root) and the calendar on a phone.
 *
 * - right-click on a card opens the card's kebab items at the pointer; a submenu item writes through the menu route;
 * - Shift+F10 on a focused card and on a column header opens the same items; Escape gives the focus back;
 * - a long press (touch, Chromium's CDP touch events) opens them on a phone;
 * - the focus toggle hides the demo header and Escape leaves;
 * - the calendar never widens the page at phone width and its toolbar wraps inside it.
 */
test.describe.configure({ timeout: 60_000 });

const menu = (page: Page): Locator => page.locator('.nowo-ui-context-menu:not(.nowo-ui-context-menu--sub)');
const submenu = (page: Page): Locator => page.locator('.nowo-ui-context-menu--sub');
const isPhone = (page: Page): boolean => (page.viewportSize()?.width ?? 1280) <= 640;

function watch(page: Page): string[] {
  const errors: string[] = [];
  page.on('pageerror', (e) => errors.push(e.message));
  page.on('console', (m) => {
    if (m.type() === 'error' && !/Failed to load resource|favicon|mercure|_wdt/.test(m.text())) {
      errors.push(m.text());
    }
  });

  return errors;
}

async function openBoard(page: Page): Promise<string> {
  await page.goto('/tools/task-board');
  await page.getByRole('link', { name: /Platform Squad/ }).first().click();
  await expect(page.locator('.nowo-task-board')).toBeVisible();
  const url = page.url().replace(/[?#].*$/, '');
  await page.goto(`${url}?view=board`);

  return url;
}

/** A free spot of a card: below its title, away from the title link and the kebab. */
async function spot(locator: Locator): Promise<{ x: number; y: number }> {
  await locator.scrollIntoViewIfNeeded();
  const box = (await locator.boundingBox())!;

  return { x: box.x + 8, y: box.y + box.height - 6 };
}

test('right-click on a card opens its menu; Priority › Urgent writes and the card shows it', async ({ page }) => {
  test.skip(isPhone(page), 'pointer devices');
  const errors = watch(page);
  await openBoard(page);
  const card = page.locator('.nowo-task-card--menu').first();
  const title = (await card.getAttribute('data-title')) ?? '';
  const at = await spot(card);

  await page.mouse.click(at.x, at.y, { button: 'right' });
  await expect(menu(page)).toBeVisible();
  await expect(card).toHaveAttribute('data-nowo-ui-context-menu-open', '');
  const items = await menu(page).getByRole('menuitem').allTextContents();
  for (const name of ['Open', 'Edit', 'Assign', 'Status', 'Priority', 'Due date', 'Copy link', 'Archive']) {
    expect(items.map((t) => t.trim())).toContain(name);
  }
  const box = (await menu(page).boundingBox())!;
  expect(Math.abs(box.x - at.x)).toBeLessThan(6);

  await menu(page).getByRole('menuitem', { name: 'Priority' }).hover();
  await expect(submenu(page)).toBeVisible();
  // The submenu lists the other priorities: take Urgent, or High when the task is already urgent.
  const offered = (await submenu(page).getByRole('menuitem').allTextContents()).map((t) => t.trim());
  const target = offered.includes('Urgent') ? 'Urgent' : 'High';
  await submenu(page).getByRole('menuitem', { name: target }).click();
  await page.waitForLoadState('load');
  await expect(page.locator(`.nowo-task-card[data-title="${title}"] .nowo-task-priority--${target.toLowerCase()}`)).toBeVisible();
  expect(errors).toEqual([]);
});

test('the kebab is reachable with Tab, and Shift+F10 on a focused card opens the same items', async ({ page }) => {
  test.skip(isPhone(page), 'keyboard');
  await openBoard(page);
  const card = page.locator('.nowo-task-card--menu').first();
  await card.focus();
  await expect(card).toBeFocused();

  await page.keyboard.press('Shift+F10');
  await expect(menu(page)).toBeVisible();
  await page.keyboard.press('ArrowDown');
  await page.keyboard.press('Escape');
  await expect(menu(page)).toHaveCount(0);
  await expect(card).toBeFocused();

  // Tab from the card reaches its title link, then its kebab toggle.
  const kebab = card.locator('[data-testid="nowo-task-menu"] > summary');
  for (let i = 0; i < 4 && !(await kebab.evaluate((el) => el === document.activeElement)); i++) {
    await page.keyboard.press('Tab');
  }
  await expect(kebab).toBeFocused();
  await page.keyboard.press('Enter');
  await expect(card.locator('[data-testid="nowo-task-menu-panel"]')).toBeVisible();
});

test('a column header: Shift+F10 opens Rename / Add task / Hide, and Hide folds the column until it is shown again', async ({ page }) => {
  test.skip(isPhone(page), 'keyboard');
  await openBoard(page);
  const column = page.locator('.nowo-task-column').first();
  const header = column.locator('.nowo-task-column__header');
  await header.focus();
  await page.keyboard.press('Shift+F10');
  await expect(menu(page)).toBeVisible();
  await expect(menu(page).getByRole('menuitem')).toHaveText(['Rename', 'Add task', 'Hide / collapse']);
  await menu(page).getByRole('menuitem', { name: 'Hide / collapse' }).click();
  await expect(column).toHaveClass(/is-collapsed/);
  await expect(column.locator('.nowo-task-column__tasks')).toBeHidden();

  await page.reload();
  await expect(column).toHaveClass(/is-collapsed/);
  await header.click({ button: 'right', position: { x: 4, y: 4 } });
  await menu(page).getByRole('menuitem', { name: 'Show column' }).click();
  await expect(column).not.toHaveClass(/is-collapsed/);
});

test('a long press on a card opens its menu on a phone', async ({ page, browserName }) => {
  test.skip(!isPhone(page) || browserName !== 'chromium', 'touch input through CDP (Chromium phone project)');
  await openBoard(page);
  const cdp: CDPSession = await page.context().newCDPSession(page);
  const at = await spot(page.locator('.nowo-task-card--menu').first());

  await cdp.send('Input.dispatchTouchEvent', { type: 'touchStart', touchPoints: [{ x: at.x, y: at.y, id: 1 }] });
  await page.waitForTimeout(800);
  await expect(menu(page)).toBeVisible();
  await cdp.send('Input.dispatchTouchEvent', { type: 'touchEnd', touchPoints: [] });
  await page.waitForTimeout(200);
  await expect(menu(page)).toBeVisible();
  for (const item of await menu(page).getByRole('menuitem').all()) {
    expect((await item.boundingBox())!.height).toBeGreaterThanOrEqual(43);
  }
});

test('the focus toggle hides the shell header on the board, list, calendar and task pages; Escape leaves', async ({ page }) => {
  const board = await openBoard(page);
  for (const path of [`${board}?view=board`, `${board}/list`, `${board}/calendar`]) {
    await page.goto(path);
    const toggle = page.locator('[data-testid="nowo-task-focus-toggle"] [data-nowo-ui-focus-mode]');
    await expect(toggle).toHaveAttribute('data-nowo-ui-focus-area', 'tasks');
    await toggle.click();
    await expect(page.getByTestId('demo-shell')).toHaveAttribute('data-focus-mode', 'on');
    await expect(page.getByTestId('demo-masthead')).toBeHidden();
    await expect(toggle).toHaveAttribute('aria-pressed', 'true');
    await page.locator('body').click({ position: { x: 2, y: 2 } });
    await page.keyboard.press('Escape');
    await expect(page.getByTestId('demo-shell')).not.toHaveAttribute('data-focus-mode', /on|fullscreen/);
    await expect(page.getByTestId('demo-masthead')).toBeVisible();
  }
  await page.goto(`${board}?view=board`);
  await page.locator('.nowo-task-card__title').first().click();
  await expect(page.locator('.nowo-task-page [data-testid="nowo-task-focus-toggle"] [data-nowo-ui-focus-mode]')).toBeVisible();
});

test('the calendar never widens the page at phone width, and its toolbar wraps inside it', async ({ page }) => {
  test.skip(!isPhone(page), 'phone width');
  const board = await openBoard(page);
  for (const mode of ['month', 'week', 'list']) {
    await page.goto(`${board}/calendar?mode=${mode}`);
    await expect(page.locator('.nowo-task-cal')).toBeVisible();
    const { scroll, client } = await page.evaluate(() => ({ scroll: document.documentElement.scrollWidth, client: document.documentElement.clientWidth }));
    expect(scroll, mode).toBeLessThanOrEqual(client);
    const viewport = page.viewportSize()!.width;
    for (const part of ['.nowo-task-cal__nav', '.nowo-task-cal__period', '.nowo-task-cal__modes']) {
      const box = (await page.locator(part).first().boundingBox())!;
      expect(box.x + box.width, `${mode} ${part}`).toBeLessThanOrEqual(viewport + 1);
    }
    // The screen-reader texts of the calendar sit inside it.
    expect(await page.locator('.nowo-task-cal').evaluate((el) => getComputedStyle(el).position)).toBe('relative');
  }
});
