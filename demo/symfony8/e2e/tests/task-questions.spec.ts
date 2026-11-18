import { expect, test, type Locator, type Page } from '@playwright/test';

/**
 * Since 2.23.0 (Beacon spec 242): questions on tasks. The demo turns on `task_questions` with a recipient resolver that
 * offers every demo user, the visitor included, so the one visitor asks and answers.
 *
 * - "Ask a question…" in a card's «⋮» menu opens the task page's ask form;
 * - the question card shows the options with the recommended one; the answer form writes, a second answer with the old
 *   version is refused (someone answered meanwhile) and a new one supersedes it, both kept;
 * - the card on the board and the list row show "Waiting for answer" while it is open, and the list filter keeps them;
 * - My open questions lists it; withdrawing it needs a reason;
 * - on a phone nothing widens the page.
 */
test.describe.configure({ timeout: 90_000 });

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

async function noSidewaysScroll(page: Page): Promise<void> {
  const overflow = await page.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth);
  expect(overflow).toBeLessThanOrEqual(1);
}

/** Opens a card's answer form (already open for a recipient while the question is open). */
async function openAnswer(card: Locator): Promise<void> {
  const box = card.locator('details.nowo-task-question__answer-box');
  if ((await box.getAttribute('open')) === null) {
    await card.getByTestId('nowo-task-question-answer-toggle').click();
  }
}

async function ask(page: Page, text: string): Promise<Locator> {
  const form = page.getByTestId('nowo-task-question-form');
  await expect(form).toBeVisible();
  await form.getByLabel('Question', { exact: true }).fill(text);
  await form.getByLabel('Context').fill('We need it **today**.');
  await form.getByLabel('Option 1', { exact: true }).fill('Hetzner');
  await form.getByLabel('Option 2', { exact: true }).fill('AWS');
  await form.getByRole('radio', { name: '1', exact: true }).check();
  await form.getByRole('checkbox', { name: /demo/i }).first().check();
  await form.getByTestId('nowo-task-question-submit').click();
  await page.waitForLoadState('load');
  const card = page.getByTestId('nowo-task-question').filter({ hasText: text });
  await expect(card).toBeVisible();

  return card;
}

test('ask from the task menu, answer, a late answer is refused, supersede, withdraw', async ({ page }) => {
  test.skip(isPhone(page), 'pointer devices');
  const errors = watch(page);
  const board = await openBoard(page);
  const card = page.locator('.nowo-task-card--menu').first();
  const title = (await card.getAttribute('data-title')) ?? '';
  await card.getByTestId('nowo-task-menu').locator('summary').first().click();
  await card.locator('[data-nowo-task-menu="ask-question"]').click();
  await page.waitForLoadState('load');
  await expect(page.locator('#nowo-task-question-ask')).toHaveAttribute('open', '');

  const text = `Which hosting? ${Date.now()}`;
  const question = await ask(page, text);
  await expect(question.getByTestId('nowo-task-question-state')).toHaveText('Waiting for answer');
  await expect(question.getByTestId('nowo-task-question-recommended')).toHaveText('Recommended');
  // The host's text renderer prints the context (the demo's is plain text).
  await expect(question.locator('.nowo-task-question__context')).toContainText('We need it');
  const taskUrl = page.url().replace(/[?#].*$/, '');

  // A second tab keeps the old version: its answer is refused (the first one stays), never written over it.
  const stale = await page.context().newPage();
  await stale.goto(taskUrl);
  const staleCard = stale.getByTestId('nowo-task-question').filter({ hasText: text });

  await openAnswer(question);
  await question.getByRole('radio', { name: 'Hetzner' }).check();
  await question.getByTestId('nowo-task-question-answer-submit').click();
  await page.waitForLoadState('load');
  const answered = page.getByTestId('nowo-task-question').filter({ hasText: text });
  await expect(answered.getByTestId('nowo-task-question-state')).toHaveText('Answered');
  await expect(answered.getByTestId('nowo-task-question-answer')).toContainText('Hetzner');

  await openAnswer(staleCard);
  await staleCard.getByRole('radio', { name: 'AWS' }).check();
  await staleCard.getByTestId('nowo-task-question-answer-submit').click();
  await stale.waitForLoadState('load');
  await expect(stale.getByTestId('nowo-task-question').filter({ hasText: text }).getByTestId('nowo-task-question-answer')).toContainText('Hetzner');
  await stale.close();

  await openAnswer(answered);
  await answered.getByRole('radio', { name: 'AWS' }).check();
  await answered.getByTestId('nowo-task-question-answer-submit').click();
  await page.waitForLoadState('load');
  const superseded = page.getByTestId('nowo-task-question').filter({ hasText: text });
  await expect(superseded.getByTestId('nowo-task-question-answer')).toContainText('AWS');
  await expect(superseded.locator('.nowo-task-question__history summary')).toHaveText('1 earlier answer');

  await superseded.getByTestId('nowo-task-question-withdraw-toggle').click();
  await superseded.getByLabel('Why is it withdrawn?').fill('Decided in the call');
  await superseded.getByTestId('nowo-task-question-withdraw-submit').click();
  await page.waitForLoadState('load');
  const withdrawn = page.getByTestId('nowo-task-question').filter({ hasText: text });
  await expect(withdrawn.getByTestId('nowo-task-question-state')).toHaveText('Withdrawn');
  await expect(withdrawn.getByTestId('nowo-task-question-withdrawn')).toContainText('Decided in the call');

  // The task's status never moved: the card is still in its column on the board.
  await page.goto(`${board}?view=board`);
  await expect(page.locator(`.nowo-task-card[data-title="${title}"]`)).toBeVisible();
  expect(errors).toEqual([]);
});

test('Waiting for answer on the board and the list, the list filter, and My open questions', async ({ page }) => {
  const errors = watch(page);
  const board = await openBoard(page);
  const card = page.locator('.nowo-task-card').nth(1);
  const title = (await card.getAttribute('data-title')) ?? '';
  await card.locator('.nowo-task-card__title').click();
  await page.waitForLoadState('load');
  await page.goto(page.url().replace(/[?#].*$/, '') + '?ask=1');
  const text = `Budget approved? ${Date.now()}`;
  await ask(page, text);
  if (isPhone(page)) {
    await noSidewaysScroll(page);
  }

  await page.goto(`${board}?view=board`);
  await expect(page.locator(`.nowo-task-card[data-title="${title}"] [data-testid="nowo-task-waiting"]`)).toBeVisible();

  await page.goto(`${board}/list`);
  const row = page.locator(`tr[data-title="${title}"]`);
  await expect(row).toHaveAttribute('data-waiting', '1');
  const waiting = page.getByLabel('Waiting for answer');
  if (!(await waiting.isVisible())) {
    await page.locator('.nowo-task-filter-fold__toggle, [data-nowo-task-board-filter-fold-target="toggle"]').first().click();
  }
  await waiting.check();
  await expect(row).toBeVisible();
  await expect(page.locator('tr[data-waiting="0"]').first()).toBeHidden();

  await page.goto('/tools/task-board/questions');
  await expect(page.getByTestId('nowo-task-my-question-link').filter({ hasText: text })).toBeVisible();
  if (isPhone(page)) {
    await noSidewaysScroll(page);
  }
  await page.goto('/tools/task-board/questions?tab=asked');
  await expect(page.getByTestId('nowo-task-my-question-link').filter({ hasText: text })).toBeVisible();
  expect(errors).toEqual([]);
});

test.describe('at 390 px', () => {
  test.use({ viewport: { width: 390, height: 844 } });

  test('the questions section and My open questions never scroll sideways', async ({ page }) => {
    const errors = watch(page);
    await openBoard(page);
    await page.locator('.nowo-task-card .nowo-task-card__title').first().click();
    await page.waitForLoadState('load');
    await page.goto(page.url().replace(/[?#].*$/, '') + '?ask=1');
    await expect(page.getByTestId('nowo-task-questions')).toBeVisible();
    await expect(page.getByTestId('nowo-task-question-form')).toBeVisible();
    await noSidewaysScroll(page);
    await page.goto('/tools/task-board/questions');
    await expect(page.getByTestId('nowo-task-my-questions')).toBeVisible();
    await noSidewaysScroll(page);
    expect(errors).toEqual([]);
  });
});
