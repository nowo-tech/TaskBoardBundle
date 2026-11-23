<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Tests\Functional\Question;

use DateTimeImmutable;
use Nowo\TaskBoardBundle\Question\Entity\TaskQuestion;
use Nowo\TaskBoardBundle\Question\Entity\TaskQuestionOption;
use Nowo\TaskBoardBundle\Question\Entity\TaskQuestionRecipient;
use Nowo\TaskBoardBundle\Question\TaskQuestionAnswerInput;
use Nowo\TaskBoardBundle\Question\TaskQuestionChoiceMode;
use Nowo\TaskBoardBundle\Question\TaskQuestionState;
use Nowo\TaskBoardBundle\Question\TaskQuestionView;
use Nowo\TaskBoardBundle\Tests\Functional\App\PredictableCsrfTokenManager;
use Nowo\TaskBoardBundle\Tests\Functional\App\RecordingLogger;
use Symfony\Component\HttpFoundation\Session\FlashBagAwareSessionInterface;

use function array_map;
use function str_replace;

/**
 * Beacon spec 242: the Questions section of the task page and drawer, its forms (CSRF, 404 / 403, the version of a racing
 * answer), the Waiting for answer badge and filter, the task menu entry and My open questions.
 */
final class TaskQuestionPagesTest extends QuestionTestCase
{
    public function testTheTaskPageShowsTheSectionAndAsksAQuestion(): void
    {
        $this->login($this->owner);
        $crawler = $this->client->request('GET', '/tools/task-board/task/' . $this->task->getId() . '?ask=1');
        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('[data-testid="nowo-task-questions"]'));
        self::assertCount(1, $crawler->filter('details#nowo-task-question-ask[open]'));
        self::assertCount(1, $crawler->filter('[data-testid="nowo-task-comment-ask-question"]'));
        self::assertStringContainsString('bob@example.com', $crawler->filter('[data-testid="nowo-task-question-form"]')->text());

        $this->client->request('POST', '/tools/task-board/task/' . $this->task->getId() . '/question', ['task_question' => [
            'text'               => 'Which hosting?',
            'context'            => "We need it **today**.\n\n<a data-nowo-ui-context-menu-source=\"x\" href=\"#\">x</a>",
            'option1'            => 'Hetzner',
            'option1Description' => 'EU',
            'option2'            => 'AWS',
            'option3'            => '',
            'option4'            => '',
            'recommended'        => '1',
            'choiceMode'         => 'single',
            'recipients'         => [$this->subject($this->bob)],
            '_token'             => PredictableCsrfTokenManager::value(TaskQuestionView::csrfId('ask')),
        ]]);
        self::assertResponseRedirects('/tools/task-board/task/' . $this->task->getId() . '#nowo-task-questions');
        $question = $this->only();
        self::assertSame(['Hetzner', 'AWS'], array_map(static fn (TaskQuestionOption $o): string => $o->getLabel(), $question->getOptions()));
        self::assertTrue($question->getOptions()[0]->isRecommended());
        self::assertTrue($this->logged('task_question_ask'));

        $crawler = $this->client->request('GET', '/tools/task-board/task/' . $this->task->getId());
        self::assertSame('open', $crawler->filter('[data-testid="nowo-task-question"]')->attr('data-question-state'));
        self::assertCount(1, $crawler->filter('[data-testid="nowo-task-question-recommended"]'));
        $html = (string) $this->client->getResponse()->getContent();
        self::assertStringNotContainsString('data-nowo-ui-context-menu-source="x"', $html, 'Context Markdown goes through the reserved-attribute stripper');
        // The asker may withdraw; not a recipient, so no answer. Since 2.31.0 the card renders no form: links to the
        // on-demand dialogs, the answer and withdraw ones with the version the card shows.
        self::assertCount(0, $crawler->filter('[data-testid="nowo-task-question"] form'));
        self::assertCount(0, $crawler->filter('[data-testid="nowo-task-question-answer-toggle"]'));
        $withdraw = $crawler->filter('a[data-testid="nowo-task-question-withdraw-toggle"]');
        self::assertCount(1, $withdraw);
        self::assertStringEndsWith('/question/' . $question->getId() . '/withdraw?version=1', (string) $withdraw->attr('href'));
        self::assertSame('nowo-task-board-task-dialog-' . $this->task->getId(), $withdraw->attr('data-nowo-ui-dialog-open'));
    }

    public function testAnInvalidAskIsAFlashAndAForgedOneIsRefused(): void
    {
        $this->login($this->owner);
        $path = '/tools/task-board/task/' . $this->task->getId() . '/question';
        $this->client->request('POST', $path, ['task_question' => ['text' => '', 'recipients' => [$this->subject($this->bob)], 'choiceMode' => 'single', '_token' => PredictableCsrfTokenManager::value(TaskQuestionView::csrfId('ask'))]]);
        self::assertResponseRedirects();
        self::assertSame([], $this->em->getRepository(TaskQuestion::class)->findAll());
        // One option only: the form is valid, the manager refuses it.
        $this->client->request('POST', $path, ['task_question' => ['text' => 'Q', 'option1' => 'Only', 'recipients' => [$this->subject($this->bob)], 'choiceMode' => 'single', '_token' => PredictableCsrfTokenManager::value(TaskQuestionView::csrfId('ask'))]]);
        self::assertResponseRedirects();
        self::assertSame([], $this->em->getRepository(TaskQuestion::class)->findAll());
        $this->client->request('POST', $path, ['task_question' => ['text' => 'Q', 'recipients' => [$this->subject($this->bob)], 'choiceMode' => 'single', '_token' => 'forged']]);
        self::assertResponseStatusCodeSame(403);
        $this->client->request('POST', $path, []);
        self::assertResponseStatusCodeSame(403);
        $this->client->request('POST', '/tools/task-board/task/missing/question', []);
        self::assertResponseStatusCodeSame(404);
    }

    public function testTheRecipientAnswersAndALateAnswerIsAConflict(): void
    {
        $question = $this->ask();
        $name     = 'qa_' . str_replace('-', '', $question->getId());
        $path     = '/tools/task-board/task/' . $this->task->getId() . '/question/' . $question->getId() . '/answer';
        $this->login($this->bob);
        $crawler = $this->client->request('GET', '/tools/task-board/task/' . $this->task->getId());
        self::assertCount(0, $crawler->filter('[data-testid="nowo-task-question"] form'));
        self::assertCount(1, $crawler->filter('a[data-testid="nowo-task-question-answer-toggle"]'));
        self::assertCount(0, $crawler->filter('[data-testid="nowo-task-question-withdraw-toggle"]'));
        // The answer dialog: the fragment for the remote dialog, a page otherwise; the version the card showed.
        $crawler = $this->client->request('GET', $path . '?version=1', server: ['HTTP_X_NOWO_DIALOG' => '1']);
        self::assertResponseIsSuccessful();
        self::assertStringNotContainsString('<html', (string) $this->client->getResponse()->getContent());
        self::assertCount(1, $crawler->filter('[data-testid="nowo-task-question-answer-form"]'));
        self::assertSame('1', $crawler->filter('input[name="' . $name . '[version]"]')->attr('value'));
        $crawler = $this->client->request('GET', $path);
        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('[data-testid="nowo-task-dialog-page"] [data-testid="nowo-task-question-answer-form"]'));
        // Bob is no asker or manager: no withdraw dialog either.
        $this->client->request('GET', '/tools/task-board/task/' . $this->task->getId() . '/question/' . $question->getId() . '/withdraw', server: ['HTTP_X_NOWO_DIALOG' => '1']);
        self::assertResponseStatusCodeSame(403);

        $this->client->request('POST', $path, [$name => ['options' => $question->getOptions()[1]->getId(), 'version' => '1', '_token' => PredictableCsrfTokenManager::value(TaskQuestionView::csrfId('answer', $question->getId()))]]);
        self::assertResponseRedirects();
        $this->em->clear();
        $loaded = $this->manager()->find($question->getId());
        self::assertInstanceOf(TaskQuestion::class, $loaded);
        self::assertSame(TaskQuestionState::Answered, $loaded->getState());

        $this->flashes();
        // The same version again: someone answered meanwhile.
        $this->client->request('POST', $path, [$name => ['options' => $question->getOptions()[0]->getId(), 'version' => '1', '_token' => PredictableCsrfTokenManager::value(TaskQuestionView::csrfId('answer', $question->getId()))]]);
        self::assertResponseRedirects();
        self::assertSame(['error' => ['task_board.question.flash.conflict']], $this->flashes());

        $crawler = $this->client->request('GET', '/tools/task-board/task/' . $this->task->getId());
        self::assertSame('answered', $crawler->filter('[data-testid="nowo-task-question"]')->attr('data-question-state'));
        self::assertStringContainsString('AWS', $crawler->filter('[data-testid="nowo-task-question-answer"]')->text());

        // A drawer's fetch comes back to the panel.
        $this->client->request('POST', $path, [$name => ['options' => $question->getOptions()[0]->getId(), 'version' => '2', '_token' => PredictableCsrfTokenManager::value(TaskQuestionView::csrfId('answer', $question->getId()))]], [], ['HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest']);
        self::assertResponseRedirects('/tools/task-board/task/' . $this->task->getId() . '/panel');
        $this->client->request('POST', $path . '?_target_path=/tools/task-board', [$name => ['options' => $question->getOptions()[0]->getId(), 'version' => '3', '_token' => PredictableCsrfTokenManager::value(TaskQuestionView::csrfId('answer', $question->getId()))]]);
        self::assertResponseRedirects('/tools/task-board#nowo-task-questions');
        $crawler = $this->client->request('GET', '/tools/task-board/task/' . $this->task->getId());
        self::assertStringContainsString('2 earlier answers', $crawler->filter('.nowo-task-question__history summary')->text());

        // An invalid answer is a flash; a forged one 403; none submitted 403.
        $this->client->request('POST', $path, [$name => ['options' => 'not-an-option', 'version' => '4', '_token' => PredictableCsrfTokenManager::value(TaskQuestionView::csrfId('answer', $question->getId()))]]);
        self::assertResponseRedirects();
        $this->client->request('POST', $path, [$name => ['version' => '4', '_token' => PredictableCsrfTokenManager::value(TaskQuestionView::csrfId('answer', $question->getId()))]]);
        self::assertResponseRedirects();
        $this->client->request('POST', $path, [$name => ['options' => $question->getOptions()[0]->getId(), '_token' => 'forged']]);
        self::assertResponseStatusCodeSame(403);
        $this->client->request('POST', $path, []);
        self::assertResponseStatusCodeSame(403);

        // Since 2.31.0, from the dialog: an invalid answer is answered in place (422), a saved one 204 + X-Nowo-Redirect,
        // a stale version is still the conflict flash, a forged one still 403.
        $dialog  = ['HTTP_X_NOWO_DIALOG' => '1', 'HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest'];
        $crawler = $this->client->request('POST', $path, [$name => ['options' => 'not-an-option', 'version' => '4', '_token' => PredictableCsrfTokenManager::value(TaskQuestionView::csrfId('answer', $question->getId()))]], [], $dialog);
        self::assertResponseStatusCodeSame(422);
        self::assertCount(1, $crawler->filter('[data-testid="nowo-task-question-answer-form"]'));
        $this->client->request('POST', $path, [$name => ['options' => $question->getOptions()[1]->getId(), 'version' => '4', '_token' => PredictableCsrfTokenManager::value(TaskQuestionView::csrfId('answer', $question->getId()))]], [], $dialog);
        self::assertResponseStatusCodeSame(204);
        self::assertSame('/tools/task-board/task/' . $this->task->getId(), $this->client->getResponse()->headers->get('X-Nowo-Redirect'));
        $this->flashes();
        $this->client->request('POST', $path, [$name => ['options' => $question->getOptions()[0]->getId(), 'version' => '4', '_token' => PredictableCsrfTokenManager::value(TaskQuestionView::csrfId('answer', $question->getId()))]], [], $dialog);
        self::assertResponseStatusCodeSame(204);
        self::assertSame(['error' => ['task_board.question.flash.conflict']], $this->flashes());
        $this->client->request('POST', $path, [$name => ['options' => $question->getOptions()[0]->getId(), '_token' => 'forged']], [], $dialog);
        self::assertResponseStatusCodeSame(403);
    }

    /** TB-L1: a dot-segment `_target_path` on a dialog post falls back to the task URL (204), never a 500. */
    public function testADotSegmentTargetPathFallsBackToTheTaskUrl(): void
    {
        $question = $this->ask();
        $name     = 'qa_' . str_replace('-', '', $question->getId());
        $this->login($this->bob);
        $dialog = ['HTTP_X_NOWO_DIALOG' => '1', 'HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest'];
        foreach (['/tools/task-board/../x', '/tools/task-board/%2e%2e/x'] as $bad) {
            $this->client->request('POST', '/tools/task-board/task/' . $this->task->getId() . '/question/' . $question->getId() . '/answer?_target_path=' . $bad, [$name => ['options' => $question->getOptions()[0]->getId(), 'version' => '99', '_token' => PredictableCsrfTokenManager::value(TaskQuestionView::csrfId('answer', $question->getId()))]], [], $dialog);
            self::assertResponseStatusCodeSame(204, $bad);
            self::assertSame('/tools/task-board/task/' . $this->task->getId(), $this->client->getResponse()->headers->get('X-Nowo-Redirect'), $bad);
        }
    }

    public function testSomeoneWhoIsNotARecipientIsRefusedAndAnotherTasksQuestionIsNotFound(): void
    {
        $question = $this->ask();
        $other    = $this->task($this->roadmap, 'Other');
        $stranger = $this->user('stranger@example.com');
        $name     = 'qa_' . str_replace('-', '', $question->getId());
        $this->login($this->owner);
        $this->client->request('POST', '/tools/task-board/task/' . $this->task->getId() . '/question/' . $question->getId() . '/answer', [$name => ['options' => $question->getOptions()[0]->getId(), 'version' => '1', '_token' => PredictableCsrfTokenManager::value(TaskQuestionView::csrfId('answer', $question->getId()))]]);
        self::assertResponseStatusCodeSame(403);

        $this->client->request('POST', '/tools/task-board/task/' . $other->getId() . '/question/' . $question->getId() . '/answer', []);
        self::assertResponseStatusCodeSame(404);
        $this->login($stranger);
        $this->client->request('POST', '/tools/task-board/task/' . $this->task->getId() . '/question/' . $question->getId() . '/answer', []);
        self::assertResponseStatusCodeSame(404);
    }

    public function testTheAskerWithdrawsWithAReason(): void
    {
        $question = $this->ask();
        $name     = 'qw_' . str_replace('-', '', $question->getId());
        $path     = '/tools/task-board/task/' . $this->task->getId() . '/question/' . $question->getId() . '/withdraw';
        $this->login($this->owner);
        // Since 2.31.0: the withdraw dialog (fragment / page), and an empty reason from it is answered in place (422).
        $crawler = $this->client->request('GET', $path . '?version=1', server: ['HTTP_X_NOWO_DIALOG' => '1']);
        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('[data-testid="nowo-task-question-withdraw-form"] input[name="' . $name . '[reason]"][autofocus]'));
        $this->client->request('GET', $path);
        self::assertSelectorExists('[data-testid="nowo-task-dialog-page"] [data-testid="nowo-task-question-withdraw-form"]');
        $this->client->request('POST', $path, [$name => ['reason' => '', 'version' => '1', '_token' => PredictableCsrfTokenManager::value(TaskQuestionView::csrfId('withdraw', $question->getId()))]], [], ['HTTP_X_NOWO_DIALOG' => '1']);
        self::assertResponseStatusCodeSame(422);
        self::assertSelectorExists('[data-testid="nowo-task-question-withdraw-form"]');
        $this->client->request('POST', $path, [$name => ['reason' => '', 'version' => '1', '_token' => PredictableCsrfTokenManager::value(TaskQuestionView::csrfId('withdraw', $question->getId()))]]);
        self::assertResponseRedirects();
        $this->client->request('POST', $path, [$name => ['reason' => 'Decided in the call', 'version' => '1', '_token' => PredictableCsrfTokenManager::value(TaskQuestionView::csrfId('withdraw', $question->getId()))]]);
        self::assertResponseRedirects();
        $crawler = $this->client->request('GET', '/tools/task-board/task/' . $this->task->getId());
        self::assertSame('withdrawn', $crawler->filter('[data-testid="nowo-task-question"]')->attr('data-question-state'));
        self::assertStringContainsString('Decided in the call', $crawler->filter('[data-testid="nowo-task-question-withdrawn"]')->text());
        // Withdrawn again: the form is gone (the dialog too), a forged post is refused.
        $this->client->request('GET', $path, server: ['HTTP_X_NOWO_DIALOG' => '1']);
        self::assertResponseStatusCodeSame(403);
        $this->flashes();
        $this->client->request('POST', $path, [$name => ['reason' => 'Again', 'version' => '2', '_token' => PredictableCsrfTokenManager::value(TaskQuestionView::csrfId('withdraw', $question->getId()))]]);
        self::assertResponseRedirects();
        self::assertSame(['error' => ['task_board.question.flash.refused.withdrawn']], $this->flashes());
        $this->client->request('POST', $path, [$name => ['reason' => 'Again', '_token' => 'forged']]);
        self::assertResponseStatusCodeSame(403);
        $this->client->request('POST', $path, []);
        self::assertResponseStatusCodeSame(403);

        $answer = 'qa_' . str_replace('-', '', $question->getId());
        $this->login($this->bob);
        $this->client->request('POST', '/tools/task-board/task/' . $this->task->getId() . '/question/' . $question->getId() . '/answer', [$answer => ['options' => $question->getOptions()[0]->getId(), 'version' => '2', '_token' => PredictableCsrfTokenManager::value(TaskQuestionView::csrfId('answer', $question->getId()))]]);
        self::assertResponseRedirects();
        self::assertSame(['error' => ['task_board.question.flash.refused.withdrawn']], $this->flashes());
    }

    public function testTheBadgeTheFilterAndTheMenuEntry(): void
    {
        $this->ask();
        $this->login($this->owner);
        $crawler = $this->client->request('GET', '/tools/task-board/board/' . $this->roadmap->getId());
        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('[data-task-id="' . $this->task->getId() . '"][data-waiting="1"] [data-testid="nowo-task-waiting"]'));

        $crawler = $this->client->request('GET', '/tools/task-board/board/' . $this->roadmap->getId() . '/list');
        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('tr[data-task-id="' . $this->task->getId() . '"][data-waiting="1"]'));
        self::assertCount(1, $crawler->filter('input[name="waitingForAnswer"]'));
    }

    public function testMyOpenQuestions(): void
    {
        $question = $this->ask();
        $answered = $this->ask();
        $this->manager()->answer($this->bob, $answered, new TaskQuestionAnswerInput([$answered->getOptions()[0]->getId()]));
        $hidden = $this->board('Hidden', creator: $this->user('carol@example.com'));
        $this->login($this->bob);
        $crawler = $this->client->request('GET', '/tools/task-board/questions');
        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('[data-testid="nowo-task-my-question-row"]'));
        self::assertStringContainsString($question->getId(), (string) $crawler->filter('[data-testid="nowo-task-my-question-link"]')->attr('href'));

        $crawler = $this->client->request('GET', '/tools/task-board/questions?tab=asked&per_page=7&page=0');
        self::assertResponseIsSuccessful();
        self::assertCount(0, $crawler->filter('[data-testid="nowo-task-my-question-row"]'));
        // 2.30: the empty list is UiKit's `empty`: one sentence, no default second line.
        self::assertCount(1, $crawler->filter('.nowo-ui-empty__message'));
        self::assertSame('None of your questions is waiting for an answer.', $crawler->filter('.nowo-ui-empty__message')->text());
        self::assertCount(0, $crawler->filter('.nowo-ui-empty__title'));
        self::assertStringNotContainsString('No items found', (string) $this->client->getResponse()->getContent());

        $this->login($this->owner);
        $crawler = $this->client->request('GET', '/tools/task-board/questions?tab=asked');
        self::assertCount(1, $crawler->filter('[data-testid="nowo-task-my-question-row"]'));
        self::assertNotNull($hidden->getId());
    }

    /**
     * Since 2.23.1: without tenancy too, My open questions lists only the questions of tasks the viewer may see — a
     * question addressed to bob on a board he may not open (carol's) never shows, to answer or as a count.
     */
    public function testMyOpenQuestionsLeaveOutTheBoardsTheViewerMayNotSee(): void
    {
        $visible = $this->ask();
        $carol   = $this->user('carol@example.com');
        $secret  = $this->task($this->board('Secret', creator: $carol), 'Secret task');
        $hidden  = new TaskQuestion($secret, 'Secret question', null, TaskQuestionChoiceMode::Single, true, $carol, new DateTimeImmutable());
        $hidden->addRecipient(new TaskQuestionRecipient($hidden, $this->subject($this->bob), 'bob', false, 0));
        $this->em->persist($hidden);
        $this->em->flush();
        $this->login($this->bob);

        $crawler = $this->client->request('GET', '/tools/task-board/questions');
        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('[data-testid="nowo-task-my-question-row"]'));
        self::assertStringContainsString($visible->getId(), (string) $crawler->filter('[data-testid="nowo-task-my-question-link"]')->attr('href'));
        self::assertStringNotContainsString('Secret question', (string) $this->client->getResponse()->getContent());
    }

    /** @return array<string, list<string>> */
    private function flashes(): array
    {
        $session = $this->client->getRequest()->getSession();
        self::assertInstanceOf(FlashBagAwareSessionInterface::class, $session);
        $flashes = $session->getFlashBag()->all();
        $session->save();

        return $flashes;
    }

    private function only(): TaskQuestion
    {
        $all = $this->em->getRepository(TaskQuestion::class)->findAll();
        self::assertCount(1, $all);

        return $all[0];
    }

    private function logged(string $action): bool
    {
        $logger = self::getContainer()->get('logger');
        if (!$logger instanceof RecordingLogger) {
            return true;
        }
        foreach ($logger->records as $record) {
            if (($record['context']['action'] ?? null) === $action) {
                return true;
            }
        }

        return false;
    }
}
