<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Tests\Functional\Characterization;

use Nowo\TaskBoardBundle\Entity\Task;
use Nowo\TaskBoardBundle\Entity\TaskBoard;
use Nowo\TaskBoardBundle\Entity\TaskChangeHistory;
use Nowo\TaskBoardBundle\Enum\TaskChangeType;
use Nowo\TaskBoardBundle\Tests\Functional\App\PredictableCsrfTokenManager;
use Nowo\TaskBoardBundle\Tests\Functional\App\TestSyncLimitChecker;
use Nowo\TaskBoardBundle\Tests\Functional\Sync\SyncFunctionalTestCase;

/**
 * Spec 213 S0: the restore route's output with the host's limit wired, pinned before the rule moves to the service.
 */
final class UiRestoreCharacterizationTest extends SyncFunctionalTestCase
{
    use CapturesUiOutput;

    private const BASE = '/tools/task-board/';

    public function testAnArchivedTaskIsRestoredAndRecorded(): void
    {
        $board = $this->board();
        $task  = $this->archived($board, 'Back');
        $this->login($this->owner);
        $this->logger()->records = [];

        $this->restore($task);

        self::assertSame([
            'status'   => 302,
            'location' => self::BASE . 'board/' . $board->getId() . '/list',
            'flashes'  => ['success' => ['The task was restored.']],
            'writes'   => ['task_restore'],
            'refused'  => [],
        ], $this->captured());
        self::assertSame([[1, 0]], TestSyncLimitChecker::$calls);
        $this->em->clear();
        $reloaded = $this->em->find(Task::class, $task->getId());
        self::assertInstanceOf(Task::class, $reloaded);
        self::assertFalse($reloaded->isArchived());
        $history = $this->em->getRepository(TaskChangeHistory::class)->findBy(['task' => $reloaded, 'changeType' => TaskChangeType::Restored]);
        self::assertCount(1, $history);
        self::assertSame('manual', $history[0]->getNewValue());
    }

    public function testACompletedTaskAsksTheLimitForNothing(): void
    {
        $board = $this->board();
        $task  = $this->archived($board, 'Done', completed: true);
        $this->login($this->owner);

        $this->restore($task);

        self::assertSame([[0, 0]], TestSyncLimitChecker::$calls);
        self::assertSame(['task_restore'], $this->captured()['writes']);
    }

    public function testARefusedRestoreChangesNothingAndSaysSo(): void
    {
        $board = $this->board();
        $task  = $this->archived($board, 'Over the limit');
        $this->login($this->owner);
        $this->logger()->records           = [];
        TestSyncLimitChecker::$refuseTasks = true;

        $this->restore($task);

        self::assertSame([
            'status'   => 302,
            'location' => self::BASE . 'board/' . $board->getId() . '/list',
            'flashes'  => ['error' => ['The task was not restored: the limit of active tasks was reached.']],
            'writes'   => [],
            'refused'  => [],
        ], $this->captured());
        $this->em->clear();
        self::assertTrue($this->em->find(Task::class, $task->getId())?->isArchived());
        self::assertSame([], $this->em->getRepository(TaskChangeHistory::class)->findBy(['changeType' => TaskChangeType::Restored]));
    }

    public function testATaskThatIsNotArchivedIsLeftAloneWithoutAskingTheLimit(): void
    {
        $board = $this->board();
        $task  = $this->task($board, 'Active');
        $this->login($this->owner);
        $this->logger()->records = [];

        $this->restore($task);

        self::assertSame([
            'status'   => 302,
            'location' => self::BASE . 'board/' . $board->getId() . '/list',
            'flashes'  => [],
            'writes'   => [],
            'refused'  => [],
        ], $this->captured());
        self::assertSame([], TestSyncLimitChecker::$calls);
    }

    public function testAForgedTokenIsRefusedBeforeAnything(): void
    {
        $board = $this->board();
        $task  = $this->archived($board, 'Stays');
        $this->login($this->owner);
        $this->logger()->records = [];

        $this->client->request('POST', self::BASE . 'task/' . $task->getId() . '/restore', ['_token' => 'forged']);

        $captured = $this->captured();
        self::assertSame(403, $captured['status']);
        self::assertSame(['csrf_invalid'], $captured['refused']);
        self::assertSame([], TestSyncLimitChecker::$calls);
    }

    private function archived(TaskBoard $board, string $title, bool $completed = false): Task
    {
        $task = new Task(board: $board, title: $title, creator: $this->owner, column: $board->getColumns()->first() ?: null);
        if ($completed) {
            $task->markCompleted();
        }
        $task->archive();
        $this->em->persist($task);
        $this->em->flush();

        return $task;
    }

    private function restore(Task $task): void
    {
        $this->client->request('POST', self::BASE . 'task/' . $task->getId() . '/restore', ['_token' => PredictableCsrfTokenManager::value('task_board_task_restore')]);
    }
}
