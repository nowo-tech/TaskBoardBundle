import { expect, test, type Page } from '@playwright/test';

/**
 * Since 2.31.0 (owner rule 2026-11-23, every dialog loads its content on demand): the task page renders no dialog body
 * and no dialog form; each opener is a link to its write route's GET, which answers the fragment for `X-Nowo-Dialog: 1`
 * (UiKit's remote dialog) and a page with the same form otherwise; an invalid post is answered in place (422), a saved
 * one with 204 + X-Nowo-Redirect.
 */
async function openTask(page: Page): Promise<string> {
  await page.goto('/tools/task-board');
  await page.getByRole('link', { name: /Platform Squad/ }).first().click();
  await expect(page.locator('.nowo-task-board')).toBeVisible();
  const href = (await page.locator('.nowo-task-card a[href*="/task/"]').first().getAttribute('href')) ?? '/';
  await page.goto(href);
  await expect(page.locator('.nowo-task-page')).toBeVisible();

  return href;
}

test('task page: no dialog form on the page, the time dialog loads, 422 in place, 204 + redirect', async ({ page }) => {
  await openTask(page);
  // Only the task's own edit form and the inline add / CSRF-only forms: none of the six dialog forms.
  for (const id of ['nowo-task-members-add-', 'nowo-task-board-priority-form-', 'nowo-task-time-form-', 'nowo-task-dependency-form-', 'nowo-task-subtask-import-form-']) {
    await expect(page.locator(`form[id^="${id}"]`)).toHaveCount(0);
  }
  await expect(page.locator('#nowo-task-link-form')).toHaveCount(0);

  const dialog = page.getByTestId('nowo-task-dialog');
  const loaded = page.waitForResponse((r) => r.request().headers()['x-nowo-dialog'] === '1' && /\/time$/.test(new URL(r.url()).pathname));
  await page.getByTestId('nowo-task-time-open').click();
  const fragment = await loaded;
  expect(fragment.status()).toBe(200);
  expect(fragment.headers()['vary'] ?? '').toMatch(/X-Nowo-Dialog/i);
  const minutes = dialog.locator('input[name$="[minutes]"]');
  await expect(minutes).toBeVisible();
  await expect(minutes).toBeFocused();

  // An invalid entry stays in the dialog with its error (422).
  await minutes.evaluate((field) => {
    field.removeAttribute('required');
    field.removeAttribute('min');
  });
  await minutes.fill('-5');
  const refused = page.waitForResponse((r) => r.request().method() === 'POST' && /\/time$/.test(new URL(r.url()).pathname));
  await dialog.locator('footer button[type="submit"]').click();
  expect((await refused).status()).toBe(422);
  await expect(dialog).toBeVisible();
  await expect(dialog.locator('input[name$="[minutes]"]')).toHaveValue('-5');

  // A valid one closes the dialog and reloads the task (204 + X-Nowo-Redirect).
  await dialog.locator('input[name$="[minutes]"]').fill('15');
  const saved = page.waitForResponse((r) => r.request().method() === 'POST' && /\/time$/.test(new URL(r.url()).pathname));
  await dialog.locator('footer button[type="submit"]').click();
  const answer = await saved;
  expect(answer.status()).toBe(204);
  expect(answer.headers()['x-nowo-redirect'] ?? '').toMatch(/\/task\//);
  await page.waitForLoadState('load');
  await expect(dialog).toBeHidden();
});

test('task page: the members and priority dialogs open on demand and close empty', async ({ page }) => {
  await openTask(page);
  const dialog = page.getByTestId('nowo-task-dialog');
  await page.getByTestId('nowo-task-members-open').click();
  await expect(dialog.locator('form[id^="nowo-task-members-add-"]')).toBeVisible();
  await page.keyboard.press('Escape');
  await expect(dialog).toBeHidden();
  await expect(dialog.locator('form')).toHaveCount(0);
  await expect(page.getByTestId('nowo-task-members-open')).toBeFocused();

  await page.getByTestId('nowo-task-priority-open').click();
  await expect(dialog.locator('form[id^="nowo-task-board-priority-form-"]')).toBeVisible();
  await expect(dialog.locator('input[type="radio"]:checked')).toBeFocused();
});

test.describe('without JavaScript', () => {
  test.use({ javaScriptEnabled: false });

  test('the opener is a link to a page with the same form', async ({ page }) => {
    await openTask(page);
    await page.getByTestId('nowo-task-time-open').click();
    await expect(page).toHaveURL(/\/time$/);
    await expect(page.getByTestId('nowo-task-dialog-page')).toBeVisible();
    await page.locator('input[name$="[minutes]"]').fill('5');
    await page.getByRole('button', { name: /Log time/ }).click();
    await expect(page.locator('.nowo-task-page')).toBeVisible();
  });
});
