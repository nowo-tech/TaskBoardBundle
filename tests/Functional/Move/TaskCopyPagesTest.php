<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Tests\Functional\Move;

use Nowo\TaskBoardBundle\Controller\TaskCopyController;
use Nowo\TaskBoardBundle\Entity\Task;
use Nowo\TaskBoardBundle\Entity\TaskBoard;
use Nowo\TaskBoardBundle\Event\TaskAccessCheckEvent;
use Nowo\TaskBoardBundle\Event\TaskBoardEvents;
use Nowo\TaskBoardBundle\Event\TaskBoardQuotaCheckEvent;
use Nowo\TaskBoardBundle\Tests\Functional\App\PredictableCsrfTokenManager;
use Nowo\TaskBoardBundle\Tests\Functional\Copy\CopyTestCase;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

use function array_map;
use function http_build_query;

/**
 * Spec 217, FR-002 – FR-005, FR-017 – FR-018: the Move and Duplicate pages with `move.enabled`, without JavaScript; the
 * options off add no route and no action.
 */
final class TaskCopyPagesTest extends CopyTestCase
{
    private const BASE = '/tools/task-board';

    protected function setUp(): void
    {
        self::$taskBoardConfig = self::$taskBoardConfig !== [] ? self::$taskBoardConfig : ['move' => ['enabled' => true]];
        parent::setUp();
    }

    public function testWithoutTheOptionThereIsNoRouteAndNoAction(): void
    {
        self::$taskBoardConfig = ['calendar' => ['enabled' => false]];
        self::ensureKernelShutdown();
        $this->setUp();
        $task = $this->task($this->board(), 'Task');
        $this->login($this->owner);

        $this->client->request('GET', self::BASE . '/task/' . $task->getId());
        self::assertResponseIsSuccessful();
        self::assertSelectorNotExists('[data-testid="nowo-task-move-board"]');
        self::assertSelectorNotExists('[data-testid="nowo-task-duplicate"]');
        $this->client->request('GET', self::BASE . '/task/' . $task->getId() . '/move-board');
        self::assertResponseStatusCodeSame(404);
        $this->client->request('GET', self::BASE . '/task/' . $task->getId() . '/duplicate');
        self::assertResponseStatusCodeSame(404);
    }

    public function testThePanelOffersBothActionsAndTheMovePageMovesAfterItsPreview(): void
    {
        $source = $this->board('Source', ['To do', 'Done']);
        $target = $this->board('Target', ['Backlog', 'Done']);
        $task   = $this->task($source, 'Task');
        $this->task($source, 'Sub', 0, $task);
        $this->clear();
        $this->login($this->owner);

        $this->client->request('GET', self::BASE . '/task/' . $task->getId() . '/panel');
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('[data-testid="nowo-task-move-board"]');
        self::assertSelectorExists('[data-testid="nowo-task-duplicate"]');

        $crawler = $this->client->request('GET', $this->moveUrl($task));
        self::assertResponseIsSuccessful();
        self::assertSame(['', $target->getId()], $crawler->filter('select[name="copy[target]"] option')->each(static fn ($option): string => (string) $option->attr('value')), 'the own board is not offered');
        self::assertSelectorNotExists('[data-testid="nowo-task-move-preview"]');

        $column  = (string) $source->getColumns()->first()?->getId();
        $crawler = $this->client->request('GET', $this->moveUrl($task, ['target' => $target->getId(), 'c' . $column => (string) $target->getColumns()->first()?->getId()]));
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('[data-testid="nowo-task-move-kept"]', 'This task and 1 subtask(s)');
        self::assertSelectorTextContains('[data-testid="nowo-task-move-column"]', 'To do → Backlog (2)');
        self::assertSelectorTextContains('[data-testid="nowo-task-move-lost"]', 'Nothing is lost.');
        $fingerprint = (string) $crawler->filter('[data-testid="nowo-task-move-confirm"] input[name="fingerprint"]')->attr('value');

        // A stale preview moves nothing and shows the new one.
        $this->client->request('POST', $this->moveUrl($task, ['target' => $target->getId()]), ['_token' => PredictableCsrfTokenManager::value(TaskCopyController::MOVE_TOKEN), 'fingerprint' => 'old']);
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('[data-testid="nowo-task-copy-notice"]');
        self::assertSelectorExists('[data-testid="nowo-task-move-confirm"]');

        // A forged token is refused.
        $this->client->request('POST', $this->moveUrl($task, ['target' => $target->getId()]), ['_token' => 'forged', 'fingerprint' => $fingerprint]);
        self::assertResponseStatusCodeSame(403);

        $this->client->request('POST', $this->moveUrl($task, ['target' => $target->getId()]), ['_token' => PredictableCsrfTokenManager::value(TaskCopyController::MOVE_TOKEN), 'fingerprint' => $fingerprint]);
        self::assertResponseRedirects(self::BASE . '/task/' . $task->getId());
        $this->clear();
        self::assertSame($target->getId(), $this->reload($task)->getBoard()->getId());
        self::assertContains('task_move_board', array_map(static fn (array $record): string => (string) ($record['context']['action'] ?? ''), $this->logger()->records));
    }

    public function testATargetNotOfferedAnswers404AndAReadOnlyTask403(): void
    {
        $source  = $this->board('Source');
        $foreign = $this->board('Foreign', creator: $this->user('ana@example.com'));
        $task    = $this->task($source, 'Task');
        $this->login($this->owner);

        $this->client->request('GET', $this->moveUrl($task, ['target' => $foreign->getId()]));
        self::assertResponseStatusCodeSame(404);
        $this->client->request('GET', $this->moveUrl($task, ['target' => $source->getId()]));
        self::assertResponseStatusCodeSame(404, 'the own board is not a move target');
        $this->client->request('GET', self::BASE . '/task/unknown/move-board');
        self::assertResponseStatusCodeSame(404);

        $this->login($this->user('viewer@example.com'));
        $this->client->request('GET', $this->moveUrl($task));
        self::assertResponseStatusCodeSame(404, 'a board the user cannot see');

        $this->login($this->owner);
        $dispatcher = self::getContainer()->get('event_dispatcher');
        self::assertInstanceOf(EventDispatcherInterface::class, $dispatcher);
        $dispatcher->addListener(TaskBoardEvents::TASK_ACCESS_CHECK, static function (TaskAccessCheckEvent $event): void {
            $event->markReadOnly();
        });
        $this->client->request('GET', self::BASE . '/task/' . $task->getId() . '/panel');
        self::assertSelectorNotExists('[data-testid="nowo-task-move-board"]');
        $this->client->request('GET', $this->moveUrl($task));
        self::assertResponseStatusCodeSame(403);
        $this->client->request('POST', self::BASE . '/task/' . $task->getId() . '/duplicate', ['_token' => PredictableCsrfTokenManager::value(TaskCopyController::DUPLICATE_TOKEN)]);
        self::assertResponseStatusCodeSame(403);
    }

    public function testWithNoOtherBoardThePageSaysSoAndAGuestIsRefused(): void
    {
        $task = $this->task($this->board(), 'Alone');
        $this->client->request('GET', $this->moveUrl($task));
        self::assertFalse($this->client->getResponse()->isSuccessful(), 'a guest');
        $this->login($this->owner);

        $this->client->request('GET', $this->moveUrl($task));
        self::assertSelectorExists('[data-testid="nowo-task-move-no-target"]');
    }

    public function testRefusalsAreShownOnThePage(): void
    {
        $source = $this->board('Source');
        $target = $this->board('Target');
        $task   = $this->task($source, 'Task');
        $this->login($this->owner);

        $crawler             = $this->client->request('GET', $this->moveUrl($task, ['target' => $target->getId()]));
        $fingerprint         = (string) $crawler->filter('input[name="fingerprint"]')->attr('value');
        $this->quota()->deny = [TaskBoardQuotaCheckEvent::ACTION_TASK_MOVE];
        $this->client->request('POST', $this->moveUrl($task, ['target' => $target->getId()]), ['_token' => PredictableCsrfTokenManager::value(TaskCopyController::MOVE_TOKEN), 'fingerprint' => $fingerprint]);
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('[data-testid="nowo-task-copy-refusal"]', 'Your plan is full.');

        $this->quota()->deny = [TaskBoardQuotaCheckEvent::ACTION_TASK_CREATE];
        $crawler             = $this->client->request('GET', self::BASE . '/task/' . $task->getId() . '/duplicate');
        $fingerprint         = (string) $crawler->filter('input[name="fingerprint"]')->attr('value');
        $this->client->request('POST', self::BASE . '/task/' . $task->getId() . '/duplicate', ['_token' => PredictableCsrfTokenManager::value(TaskCopyController::DUPLICATE_TOKEN), 'fingerprint' => $fingerprint]);
        self::assertSelectorTextContains('[data-testid="nowo-task-copy-refusal"]', 'Your plan is full.');
        $this->client->request('POST', self::BASE . '/task/' . $task->getId() . '/duplicate', ['_token' => 'forged', 'fingerprint' => $fingerprint]);
        self::assertResponseStatusCodeSame(403);
        $this->client->request('POST', self::BASE . '/task/' . $task->getId() . '/duplicate', ['_token' => PredictableCsrfTokenManager::value(TaskCopyController::DUPLICATE_TOKEN), 'fingerprint' => 'old']);
        self::assertSelectorExists('[data-testid="nowo-task-copy-notice"]');
        self::assertSame(1, $this->em->getRepository(Task::class)->count([]));
    }

    public function testAPlanRefusalIsShownInsteadOfThePreview(): void
    {
        self::$taskBoardConfig = ['move' => ['enabled' => true, 'max_subtasks' => 0]];
        self::ensureKernelShutdown();
        $this->setUp();
        $source = $this->board('Source');
        $target = $this->board('Target');
        $task   = $this->task($source, 'Task');
        $this->task($source, 'Sub', 0, $task);
        $this->clear();
        $this->login($this->owner);

        $this->client->request('GET', $this->moveUrl($task, ['target' => $target->getId()]));
        self::assertSelectorTextContains('[data-testid="nowo-task-copy-refusal"]', 'Too many subtasks: 1, at most 0.');
        self::assertSelectorNotExists('[data-testid="nowo-task-move-confirm"]');
        $this->client->request('GET', self::BASE . '/task/' . $task->getId() . '/duplicate');
        self::assertSelectorTextContains('[data-testid="nowo-task-copy-refusal"]', 'Too many subtasks');
    }

    public function testTheDuplicatePageCopiesWithTheChosenOptions(): void
    {
        $board = $this->board('Source');
        $other = $this->board('Other');
        $task  = $this->task($board, 'Template');
        $this->task($board, 'Step', 0, $task);
        $this->clear();
        $this->login($this->owner);

        $crawler = $this->client->request('GET', self::BASE . '/task/' . $task->getId() . '/duplicate');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('[data-testid="nowo-task-duplicate-copied"]', 'This task and 1 subtask(s)');
        self::assertSelectorTextContains('[data-testid="nowo-task-duplicate-not-copied"]', 'Never copied: 0 comments');
        self::assertSame($board->getId(), $crawler->filter('select[name="copy[target]"] option[selected]')->attr('value'));

        $url     = self::BASE . '/task/' . $task->getId() . '/duplicate?' . http_build_query(['copy' => ['target' => $other->getId(), 'dates' => '1']]);
        $crawler = $this->client->request('GET', $url);
        self::assertSelectorTextContains('[data-testid="nowo-task-duplicate-copied"]', 'This task and 0 subtask(s)');
        $fingerprint = (string) $crawler->filter('input[name="fingerprint"]')->attr('value');
        $this->client->request('POST', $url, ['_token' => PredictableCsrfTokenManager::value(TaskCopyController::DUPLICATE_TOKEN), 'fingerprint' => $fingerprint]);

        self::assertResponseRedirects();
        $copy = $this->em->getRepository(Task::class)->findOneBy(['title' => 'Template (copy)']);
        self::assertInstanceOf(Task::class, $copy);
        self::assertSame($other->getId(), $copy->getBoard()->getId());
        self::assertCount(0, $copy->getSubtasks());
        self::assertResponseRedirects(self::BASE . '/task/' . $copy->getId());
        self::assertInstanceOf(TaskBoard::class, $copy->getBoard());
    }

    /**
     * @param array<string, string> $choices
     */
    private function moveUrl(Task $task, array $choices = []): string
    {
        return self::BASE . '/task/' . $task->getId() . '/move-board' . ($choices !== [] ? '?' . http_build_query(['copy' => $choices]) : '');
    }
}
