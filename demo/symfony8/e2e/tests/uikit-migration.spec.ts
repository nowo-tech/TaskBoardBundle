import AxeBuilder from '@axe-core/playwright';
import { expect, test, type Page } from '@playwright/test';

/**
 * Since 2.0.0: the screens that moved to UiKit, in Chromium, Firefox and WebKit: kit dialogs (overlay registry, Escape, focus
 * return, stacked), the kit's runtime confirm instead of the browser's, the scaffolds, the subtask tree, and the board's own
 * drag and drop, which stays until `sortable` is tested on a real device.
 */
function watch(page: Page): string[] {
  const errors: string[] = [];
  page.on('pageerror', (e) => {
    // The demo's dev toolbar, cancelled when a test navigates: WebKit reports it as a page error.
    if (!/_wdt/.test(e.message)) {
      errors.push(e.message);
    }
  });
  page.on('console', (m) => {
    if (m.type() === 'error' && !/Failed to load resource|favicon|mercure|_wdt/.test(m.text())) {
      errors.push(m.text());
    }
  });
  // A native window.confirm would fail these tests: only the kit's dialog may ask.
  page.on('dialog', (d) => {
    errors.push(`native dialog: ${d.message()}`);
    void d.dismiss();
  });

  return errors;
}

async function openBoard(page: Page): Promise<void> {
  await page.goto('/tools/task-board');
  await page.getByRole('link', { name: /Platform Squad/ }).first().click();
  await expect(page.locator('.nowo-task-board')).toBeVisible();
}

test('index: list scaffold and the New board dialog', async ({ page }) => {
  const errors = watch(page);
  await page.goto('/tools/task-board');
  await expect(page.locator('[data-nowo-ui-page="list"]')).toBeVisible();
  await expect(page.getByTestId('nowo-task-board-list-item').first()).toBeVisible();
  const opener = page.getByTestId('nowo-task-board-new');
  await opener.click();
  const dialog = page.getByTestId('nowo-task-board-create-dialog');
  await expect(dialog).toBeVisible();
  await expect(page.locator('html')).toHaveAttribute('data-nowo-overlay', /.+/);
  await page.keyboard.press('Escape');
  await expect(dialog).toBeHidden();
  await expect(opener).toBeFocused();

  // ?new=1 opens it on load.
  await page.goto('/tools/task-board?new=1');
  await expect(dialog).toBeVisible();
  await page.getByTestId('nowo-task-board-create-dialog').locator('[data-nowo-ui-dialog-close]').first().click();
  await expect(dialog).toBeHidden();
  expect(errors).toEqual([]);
});

test('board: dialogs fill and open, the column delete asks through the kit, drag and drop still moves a card', async ({ page }) => {
  const errors = watch(page);
  await openBoard(page);

  await page.locator('[data-action*="nowo-task-board-add-column-modal#open"]').click();
  const addColumn = page.locator('dialog[data-nowo-task-board-add-column-modal-target="modal"]');
  await expect(addColumn).toBeVisible();
  await expect(addColumn.locator('input[name$="[name]"]')).toBeFocused();
  await page.keyboard.press('Escape');
  await expect(addColumn).toBeHidden();

  // The Add task dialog: the board uses inline add, so a host's button opens it.
  await page.evaluate(() => {
    const scope = document.querySelector('[data-controller~="nowo-task-board-add-task-modal"]');
    const firstColumn = document.querySelector<HTMLElement>('.nowo-task-board__column');
    const button = document.createElement('button');
    button.type = 'button';
    button.id = 'host-add-task';
    button.dataset.action = 'click->nowo-task-board-add-task-modal#open';
    button.dataset.columnId = firstColumn?.dataset.columnId ?? '';
    button.dataset.columnName = 'Host column';
    scope?.prepend(button);
  });
  await page.locator('#host-add-task').click();
  const addTask = page.locator('dialog[data-nowo-task-board-add-task-modal-target="modal"]');
  await expect(addTask).toBeVisible();
  await expect(addTask.locator('h2')).toContainText('Host column');
  const newTask = `Dialog ${test.info().project.name} ${Date.now()}`;
  await addTask.locator('input[name="task_form[title]"]').fill(newTask);
  await expect(addTask.locator('input[name="task_form[title]"]')).toBeFocused();
  await addTask.locator('footer button[type="submit"]').click();
  await expect(page.locator(`.nowo-task-card[data-nowo-task-title="${newTask}"]`)).toBeVisible();

  await page.locator('.nowo-task-column-sets__apply').click();
  const apply = page.locator('dialog[data-dialog="apply"]');
  await expect(apply).toBeVisible();
  await apply.locator('footer [data-nowo-ui-dialog-close]').click();
  await expect(apply).toBeHidden();

  await page.locator('[data-action*="nowo-task-board-edit-column-modal#open"]').first().click();
  const edit = page.locator('dialog[data-nowo-task-board-edit-column-modal-target="modal"]');
  await expect(edit).toBeVisible();
  await edit.locator('button[form="nowo-task-board-column-delete-form"]').click();
  const confirm = page.locator('dialog.nowo-ui-confirm, dialog[data-nowo-ui-confirm]').last();
  await expect(confirm).toBeVisible();
  // The question sits over the open dialog; Escape closes only the question.
  await page.keyboard.press('Escape');
  await expect(confirm).toBeHidden();
  await expect(edit).toBeVisible();
  await page.keyboard.press('Escape');
  await expect(edit).toBeHidden();

  // The board's own drag and drop.
  const columns = page.locator('.nowo-task-board__column');
  const card = columns.first().locator('.nowo-task-card').first();
  const title = (await card.getAttribute('data-nowo-task-title')) ?? '';
  expect(title).not.toBe('');
  const moved = page.waitForResponse((r) => /\/move/.test(r.url()) && r.request().method() === 'POST');
  await card.dragTo(columns.nth(1));
  expect((await moved).status()).toBeLessThan(400);
  // The board reloads itself after a move.
  await page.waitForLoadState('networkidle');
  await expect(columns.nth(1).locator(`.nowo-task-card[data-nowo-task-title="${title}"]`)).toBeVisible();
  expect(errors).toEqual([]);
});

test('task page: show scaffold, stacked dialogs, kit tree', async ({ page }) => {
  const errors = watch(page);
  await openBoard(page);
  const href = await page.locator('.nowo-task-card a[href*="/task/"]').first().getAttribute('href');
  await page.goto(href ?? '/');
  await expect(page.locator('[data-nowo-ui-page="show"] .nowo-task-page')).toBeVisible();

  await page.locator('[data-action*="nowo-task-board-task-members-modal#open"]').first().click();
  const members = page.locator('dialog[data-nowo-task-board-task-members-modal-target="modal"]');
  await expect(members).toBeVisible();
  await page.keyboard.press('Escape');
  await expect(members).toBeHidden();

  // Help opens over Import subtasks; Escape closes one at a time.
  const importButton = page.locator('.nowo-task-subtask-import__open');
  if ((await importButton.count()) > 0) {
    await importButton.click();
    await page.locator('.nowo-task-subtask-import__help').click();
    const help = page.locator('dialog.nowo-task-import-help');
    await expect(help).toBeVisible();
    await page.keyboard.press('Escape');
    await expect(help).toBeHidden();
    await expect(page.locator('dialog[data-dialog="subtask-import"]')).toBeVisible();
    await page.keyboard.press('Escape');
  }

  // A new subtask lands in the kit's tree, as a treeitem.
  const title = `Sub ${test.info().project.name} ${Date.now()}`;
  await page.locator('input[name="task_form[title]"].nowo-task-inline-add__input').fill(title);
  await page.locator('.nowo-task-inline-add__input').press('Enter');
  await expect(page.locator('.nowo-task-subtree [role="tree"]')).toBeVisible();
  await expect(page.locator('.nowo-task-subtree [role="treeitem"]', { hasText: title })).toBeVisible();
  expect(errors).toEqual([]);
});

test('import and connection pages on their scaffolds', async ({ page }) => {
  const errors = watch(page);
  await openBoard(page);
  const board = page.url().replace(/\?.*$/, '');
  await page.goto(`${board}/import`);
  await expect(page.locator('[data-nowo-ui-page="form"] select[name="task_import_form[source]"]')).toBeVisible();
  await expect(page.locator('body')).not.toContainText('task_import_form.');
  const axe = await new AxeBuilder({ page }).withRules(['heading-order']).analyze();
  expect(axe.violations.map((v) => v.id)).toEqual([]);
  await page.locator('[data-nowo-ui-dialog-open^="nowo-task-import-help-board-"]').click();
  await expect(page.locator('dialog.nowo-task-import-help')).toBeVisible();
  await page.keyboard.press('Escape');

  await page.goto(`${board}/connection`);
  await expect(page.locator('[data-nowo-ui-page="show"]')).toBeVisible();
  expect(errors).toEqual([]);
});

test('list and Gantt keep working under the kit layout', async ({ page }) => {
  const errors = watch(page);
  await openBoard(page);
  const board = page.url().replace(/\?.*$/, '');
  await page.goto(`${board}/list`);
  await expect(page.locator('tr[data-nowo-task-title]').first()).toBeVisible();
  await expect(page.locator('h1').first()).toBeVisible();
  // K3: the kit's table, the filter fold, the time columns and their totals.
  await expect(page.locator('[data-nowo-ui-region="table"] tr.nowo-task-list__row').first()).toBeVisible();
  const rows = page.locator('tr[data-nowo-task-title]');
  const total = await rows.count();
  await page.locator('input[data-nowo-task-board-list-filter-target="search"]').fill('zzzz-no-such-task');
  await expect(rows.filter({ visible: true })).toHaveCount(0);
  await page.locator('input[data-nowo-task-board-list-filter-target="search"]').fill('');
  await expect(rows.filter({ visible: true })).toHaveCount(total);
  await page.locator('input[data-nowo-task-board-list-filter-target="timeColumns"]').check();
  await expect(page.locator('tfoot[data-nowo-task-board-list-filter-target="totals"]').first()).toBeVisible();

  // The views are tabs in the header; the Gantt scale is the kit's secondary tabs.
  await page.locator('[data-nowo-ui-region="area-nav"] a', { hasText: 'Gantt' }).click();
  await expect(page.locator('.nowo-task-gantt')).toBeVisible();
  await page.locator('[data-scale="weeks"] , a[href*="scale=weeks"]').first().click();
  await expect(page).toHaveURL(/scale=weeks/);
  await expect(page.locator('.nowo-task-gantt--weeks')).toBeVisible();
  expect(errors).toEqual([]);
});

/** Appendix HX-L1: the filter toggle works with no host peer, in the bundle's demo. */
test('list: the filter toggle folds and unfolds, the state and the count hold', async ({ page, context }) => {
  const errors = watch(page);
  await openBoard(page);
  const board = page.url().replace(/\?.*$/, '');
  await context.clearCookies({ name: 'nowo_ui_filters_collapsed' });
  await page.goto(`${board}/list`);
  const toggle = page.locator('form.nowo-task-list__filters .nowo-ui-filters-collapse__toggle');
  const search = page.locator('input[data-nowo-task-board-list-filter-target="search"]');
  const summary = page.locator('form.nowo-task-list__filters .nowo-ui-filters-collapse__summary');

  await expect(toggle).toHaveAttribute('aria-expanded', 'true');
  await expect(search).toBeVisible();
  await toggle.click();
  await expect(toggle).toHaveAttribute('aria-expanded', 'false');
  await expect(search).toBeHidden();
  await expect(summary).toBeVisible();

  // Folded across a reload, through the cookie.
  await page.reload({ waitUntil: 'load' });
  await expect(toggle).toHaveAttribute('aria-expanded', 'false');
  await expect(search).toBeHidden();

  await toggle.click();
  await expect(toggle).toHaveAttribute('aria-expanded', 'true');
  await expect(search).toBeVisible();
  await expect(summary).toBeHidden();

  // A filter folded on purpose stays folded and is counted.
  await search.fill('zzzz-no-such-task');
  await toggle.click();
  await expect(toggle).toHaveAttribute('aria-expanded', 'false');
  await expect(summary).toContainText('1');
  expect(errors).toEqual([]);
  await context.clearCookies({ name: 'nowo_ui_filters_collapsed' });
});

/** Appendix HX-L2: a filter the browser restores is applied and never folded away. */
for (const how of ['back', 'reload'] as const) {
  test(`list: a filter restored on ${how} is applied and opens the fold`, async ({ page, context }) => {
    await openBoard(page);
    const board = page.url().replace(/\?.*$/, '');
    const id = board.split('/').pop() ?? '';
    await context.clearCookies({ name: 'nowo_ui_filters_collapsed' });
    await page.goto(`${board}/list`);
    const search = page.locator('input[data-nowo-task-board-list-filter-target="search"]');
    const rows = page.locator('tr[data-nowo-task-title]');
    await search.fill('zzzz-no-such-task');
    await expect(rows.filter({ visible: true })).toHaveCount(0);

    // The card is folded in the server's answer, as a user's earlier fold would leave it.
    const url = new URL(board);
    await context.addCookies([{ name: 'nowo_ui_filters_collapsed', value: encodeURIComponent(`nowo-task-board-list-${id}`), domain: url.hostname, path: '/' }]);
    if (how === 'back') {
      await page.goto(`${board}?view=board`, { waitUntil: 'load' });
      await page.goBack({ waitUntil: 'load' });
    } else {
      await page.reload({ waitUntil: 'load' });
    }
    await page.waitForTimeout(500);

    const value = await search.inputValue();
    test.info().annotations.push({ type: 'restored', description: value });
    if (value !== '') {
      await expect(search).toBeVisible();
      await expect(page.locator('form.nowo-task-list__filters .nowo-ui-filters-collapse__toggle')).toHaveAttribute('aria-expanded', 'true');
      await expect(page.locator('form.nowo-task-list__filters .nowo-ui-filters-collapse__summary')).toBeHidden();
      await expect(rows.filter({ visible: true })).toHaveCount(0);
    } else {
      // This browser restored nothing here: every row shows, folded or not.
      await expect(rows.filter({ visible: true })).toHaveCount(await rows.count());
    }
    await context.clearCookies({ name: 'nowo_ui_filters_collapsed' });
  });
}

/** Appendix HX-L3: a link row does not widen the task page. */
test('task page: no sideways scroll at 1280 px with a link row', async ({ page }) => {
  await page.setViewportSize({ width: 1280, height: 800 });
  await openBoard(page);
  const href = await page.locator('.nowo-task-card a[href*="/task/"]').first().getAttribute('href');
  await page.goto(href ?? '/');
  if ((await page.locator('.nowo-task-detail__link-row').count()) === 0) {
    await page.locator('[data-action*="nowo-task-board-task-links-modal#openAdd"]').first().click();
    const dialog = page.locator('dialog[data-nowo-task-board-task-links-modal-target="modal"]');
    await dialog.locator('input[data-nowo-task-board-task-links-modal-target="url"]').fill('https://example.test/some/long/path');
    await dialog.locator('footer button[form="nowo-task-link-form"]').click();
    await expect(page.locator('.nowo-task-detail__link-row').first()).toBeVisible();
  }
  const width = await page.evaluate(() => document.documentElement.scrollWidth);
  expect(width).toBeLessThanOrEqual(1280);
});
