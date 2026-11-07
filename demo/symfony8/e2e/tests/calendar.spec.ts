import AxeBuilder from '@axe-core/playwright';
import { expect, test, type Page } from '@playwright/test';

/**
 * Since 2.1.0 (spec 214): the calendar and the task number, in Chromium, Firefox and WebKit (phone: Chromium). Each test adds its
 * own task on a day of the current month, so it needs no fixture with dates.
 */
function watch(page: Page): string[] {
  const errors: string[] = [];
  page.on('pageerror', (e) => {
    if (!/_wdt/.test(e.message)) {
      errors.push(e.message);
    }
  });
  page.on('console', (m) => {
    if (m.type() === 'error' && !/Failed to load resource|favicon|mercure|_wdt/.test(m.text())) {
      errors.push(m.text());
    }
  });
  page.on('dialog', (d) => {
    errors.push(`native dialog: ${d.message()}`);
    void d.dismiss();
  });

  return errors;
}

async function openCalendar(page: Page): Promise<string> {
  await page.goto('/tools/task-board');
  const link = page.getByTestId('nowo-task-board-view-calendar').first();
  const href = (await link.getAttribute('href')) ?? '';
  await page.goto(`${href}?date=${middleDay()}`);
  await expect(page.locator('.nowo-task-cal')).toBeVisible();

  return href;
}

const PROJECTS = ['desktop', 'firefox', 'webkit', 'phone'];
let monthOffset = 0;

/** Each test of each engine gets a month of its own: the runs share one database, and a day shows four bars. */
test.beforeEach(({}, info) => {
  const titleSum = [...info.title].reduce((sum, char) => sum + char.charCodeAt(0), 0);
  monthOffset = 1 + PROJECTS.indexOf(info.project.name) * 12 + (titleSum % 12);
});

/** The Wednesday in the middle of the test's month, plus days: the next day stays in its row and a week later stays in the month. */
function middleDay(offset = 0): string {
  const now = new Date();
  const tenth = new Date(Date.UTC(now.getFullYear(), now.getMonth() + monthOffset, 10));
  const wednesday = 10 + ((3 - tenth.getUTCDay() + 7) % 7);

  return new Date(Date.UTC(tenth.getUTCFullYear(), tenth.getUTCMonth(), wednesday + offset)).toISOString().slice(0, 10);
}

let counter = 0;

async function addTask(page: Page, date: string): Promise<string> {
  counter += 1;
  const title = `E2E ${test.info().project.name} ${Date.now()}-${counter}`;
  await page.locator(`[data-date="${date}"] .nowo-task-cal__add`).click();
  const dialog = page.locator('#nowo-task-board-add-task-modal');
  await expect(dialog).toBeVisible();
  await expect(dialog.locator('input[name$="[dueAt]"]')).toHaveValue(date);
  await dialog.locator('input[name$="[title]"]').fill(title);
  await dialog.getByRole('button', { name: 'Add task' }).click();
  await expect(page.locator(`.nowo-task-cal__chip[data-title="${title}"]`)).toBeVisible();

  return title;
}

async function chipDay(page: Page, title: string): Promise<string | null> {
  return page.locator(`.nowo-task-cal__chip[data-title="${title}"]`).first().evaluate((chip) => chip.closest('[role="gridcell"]')?.getAttribute('data-date') ?? null);
}

async function dragTo(page: Page, from: { x: number; y: number }, to: { x: number; y: number }): Promise<void> {
  await page.mouse.move(from.x, from.y);
  await page.mouse.down();
  await page.mouse.move(from.x + 6, from.y, { steps: 2 });
  await page.mouse.move(to.x, to.y, { steps: 8 });
  await page.mouse.up();
}

test.describe('desktop', () => {
  test.skip(({ isMobile }) => isMobile, 'Desktop only.');

  test('month grid: add on a day, axe, tab and modes', async ({ page }) => {
    const errors = watch(page);
    await openCalendar(page);
    await expect(page.locator('[role="grid"]')).toBeVisible();
    await expect(page.locator('[role="gridcell"]').first()).toBeVisible();
    await addTask(page, middleDay());
    const axe = await new AxeBuilder({ page }).include('.nowo-task-cal').disableRules(['color-contrast']).analyze();
    expect(axe.violations.map((v) => `${v.id}: ${v.nodes[0]?.target}`)).toEqual([]);
    await page.getByRole('link', { name: 'Week' }).click();
    await expect(page).toHaveURL(/mode=week/);
    await expect(page.locator('[role="gridcell"]')).toHaveCount(7);
    await page.getByRole('link', { name: 'List' }).last().click();
    await expect(page).toHaveURL(/mode=list/);
    expect(errors).toEqual([]);
  });

  test('drag the body: the task moves, Undo puts it back', async ({ page }) => {
    const errors = watch(page);
    await openCalendar(page);
    const date = middleDay();
    const title = await addTask(page, date);
    const chip = page.locator(`.nowo-task-cal__chip[data-title="${title}"]`);
    const target = page.locator(`[data-date="${middleDay(7)}"]`);
    // Both in the viewport: the rows of a month can run below it.
    await target.scrollIntoViewIfNeeded();
    const box = (await chip.boundingBox())!;
    const goal = (await target.boundingBox())!;
    await dragTo(page, { x: box.x + 20, y: box.y + box.height / 2 }, { x: goal.x + goal.width / 2, y: goal.y + goal.height / 2 });
    await expect.poll(() => chipDay(page, title)).toBe(middleDay(7));
    const toast = page.locator('[data-nowo-ui-toast]').filter({ hasText: 'Moved to' }).last();
    await expect(toast).toContainText(middleDay(7));
    await toast.getByRole('button', { name: 'Undo' }).click();
    await expect.poll(() => chipDay(page, title)).toBe(date);
    await page.reload();
    expect(await chipDay(page, title)).toBe(date);
    expect(errors).toEqual([]);
  });

  test('Reschedule dialog and an edge: same stored dates, start after due refused', async ({ page }) => {
    const errors = watch(page);
    await openCalendar(page);
    const date = middleDay();
    const title = await addTask(page, date);
    const chip = page.locator(`.nowo-task-cal__chip[data-title="${title}"]`);

    await chip.hover();
    await chip.locator('.nowo-task-cal__reschedule').click();
    const dialog = page.locator('#nowo-task-cal-reschedule');
    await expect(dialog).toBeVisible();
    await expect(dialog.locator('input[name="due"]')).toHaveValue(date);
    await dialog.locator('input[name="start"]').fill(middleDay(1));
    await dialog.getByRole('button', { name: 'Save' }).click();
    await expect(dialog.locator('[role="alert"]')).toBeVisible();
    await dialog.locator('input[name="start"]').fill(date);
    await dialog.getByRole('button', { name: 'Save' }).click();
    await expect(dialog).toBeHidden();

    // Both dates now: an edge can be dragged, and it stops at the other date.
    const bar = page.locator(`.nowo-task-cal__chip[data-title="${title}"]`);
    await expect(bar.locator('[data-edge="due"]')).toBeAttached();
    const edge = (await bar.locator('[data-edge="due"]').boundingBox())!;
    const goal = (await page.locator(`[data-date="${middleDay(1)}"]`).boundingBox())!;
    await page.mouse.move(edge.x + edge.width / 2, edge.y + edge.height / 2);
    await page.mouse.down();
    await page.mouse.move(goal.x + goal.width / 2, goal.y + goal.height / 2, { steps: 6 });
    await expect(page.locator(`[data-date="${middleDay(1)}"]`)).toHaveClass(/is-drop-range/);
    await page.mouse.up();
    await expect(page.locator(`.nowo-task-cal__chip[data-title="${title}"]`)).toHaveAttribute('data-due', middleDay(1));
    await expect(page.locator(`.nowo-task-cal__chip[data-title="${title}"]`)).toHaveAttribute('data-span', '2');
    expect(errors).toEqual([]);
  });

  test('keyboard: arrows move between days, Alt+arrow moves a task', async ({ page }) => {
    const errors = watch(page);
    await openCalendar(page);
    const date = middleDay();
    const title = await addTask(page, date);
    const cell = page.locator(`[data-date="${date}"]`);
    await cell.focus();
    await page.keyboard.press('ArrowRight');
    await expect(page.locator(`[data-date="${middleDay(1)}"]`)).toBeFocused();
    await page.keyboard.press('ArrowLeft');
    await expect(cell).toBeFocused();
    await page.keyboard.press('ArrowDown');
    await expect(page.locator(`[data-date="${middleDay(7)}"]`)).toBeFocused();

    await page.locator(`.nowo-task-cal__chip[data-title="${title}"] a`).focus();
    await page.keyboard.press('Alt+ArrowRight');
    await expect.poll(() => chipDay(page, title)).toBe(middleDay(1));
    expect(errors).toEqual([]);
  });

  test('the task number: on the chip, the list and the short link', async ({ page }) => {
    const errors = watch(page);
    const calendar = await openCalendar(page);
    const title = await addTask(page, middleDay());
    const number = (await page.locator(`.nowo-task-cal__chip[data-title="${title}"] .nowo-task-number`).innerText()).trim();
    expect(number).toMatch(/^#\d+$/);

    const boardId = /board\/([^/]+)\/calendar/.exec(calendar)?.[1] ?? '';
    await page.goto(`/tools/task-board/n/${number.slice(1)}?board=${boardId}`);
    await expect(page).toHaveURL(/\/task\//);
    await expect(page.locator('.nowo-task-detail__number')).toContainText(number);

    await page.goto(calendar.replace('/calendar', '/list'));
    await expect(page.locator(`tr[data-title="${title}"] .nowo-task-number`)).toHaveText(number);
    await page.getByPlaceholder(/search/i).fill(number);
    await expect(page.locator(`tr[data-title="${title}"]`)).toBeVisible();
    await page.goto('/tools/task-board/n/999999999');
    expect(errors).toEqual([]);
  });

  test('filters are in the URL and survive a reload', async ({ page }) => {
    const errors = watch(page);
    await openCalendar(page);
    const form = page.locator('.nowo-task-cal__filters');
    if (await form.locator('.nowo-ui-filters-collapse.is-collapsed').count()) {
      await form.locator('.nowo-ui-filters-collapse__toggle').click();
    }
    await form.locator('select[name="priority"]').selectOption('high');
    await form.getByRole('button', { name: 'Apply' }).click();
    await expect(page).toHaveURL(/priority=high/);
    await expect(page.locator('select[name="priority"]')).toHaveValue('high');
    expect(errors).toEqual([]);
  });
});

test.describe('phone', () => {
  test.skip(({ isMobile }) => !isMobile, 'Phone only.');

  test('opens in list mode, no sideways scroll, month shows counts, a week is stacked', async ({ page }) => {
    const errors = watch(page);
    const href = await openCalendar(page);
    await expect(page).toHaveURL(/mode=list/);
    const noScroll = async (): Promise<void> => {
      const wide = await page.evaluate(() => {
        const client = document.documentElement.clientWidth;

        return document.documentElement.scrollWidth <= client
          ? []
          : [...document.querySelectorAll('body *')].filter((el) => el.getBoundingClientRect().right > client + 1 && el.closest('.sf-toolbar') === null).slice(0, 8).map((el) => `${el.tagName}.${el.className}`);
      });
      expect(wide).toEqual([]);
    };
    await noScroll();

    await page.goto(`${href}?mode=month`);
    await expect(page.locator('.nowo-task-cal__chips').first()).toBeHidden();
    await noScroll();

    await page.goto(`${href}?mode=week`);
    const first = await page.locator('[role="gridcell"]').nth(0).boundingBox();
    const second = await page.locator('[role="gridcell"]').nth(1).boundingBox();
    expect(second!.y).toBeGreaterThan(first!.y);
    await noScroll();
    expect(errors).toEqual([]);
  });
});
