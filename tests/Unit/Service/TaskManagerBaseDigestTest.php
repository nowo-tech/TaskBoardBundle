<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Tests\Unit\Service;

use Nowo\TaskBoardBundle\Dto\TaskFormData;
use Nowo\TaskBoardBundle\Entity\BoardColumn;
use Nowo\TaskBoardBundle\Entity\Task;
use Nowo\TaskBoardBundle\Entity\TaskBoard;
use Nowo\TaskBoardBundle\Exception\TaskChangedException;
use Nowo\TaskBoardBundle\Repository\TaskRepositoryInterface;
use Nowo\TaskBoardBundle\Service\TaskChangeRecorder;
use Nowo\TaskBoardBundle\Service\TaskManager;
use Nowo\TaskBoardBundle\Support\TaskContentDigest;
use Nowo\TaskBoardBundle\Tests\Stub\TestUser;
use PHPUnit\Framework\TestCase;

/**
 * Since 2.31.0 (REQ-RUNTIME-003 rule 4): the edit form's content digest, without the bundle's row lock (the locked
 * revision is covered by tests/Functional/TaskEditFormRaceTest on every database).
 */
final class TaskManagerBaseDigestTest extends TestCase
{
    public function testAMatchingDigestSavesWithoutARowLock(): void
    {
        [$task, $user]    = $this->task();
        $data             = TaskFormData::fromTask($task);
        $data->title      = 'Mine';
        $data->baseDigest = TaskContentDigest::of($task);
        $repo             = $this->createMock(TaskRepositoryInterface::class);
        $repo->expects(self::once())->method('save');

        (new TaskManager($repo, new TaskChangeRecorder()))->update($task, $data, $user);

        self::assertSame('Mine', $task->getTitle());
    }

    public function testAnotherDigestIsRefusedAndWritesNothing(): void
    {
        [$task, $user]    = $this->task();
        $data             = TaskFormData::fromTask($task);
        $data->title      = 'Mine';
        $data->baseDigest = TaskContentDigest::of($task);
        $task->setDescription('Saved by someone else');
        $repo = $this->createMock(TaskRepositoryInterface::class);
        $repo->expects(self::never())->method('save');

        try {
            (new TaskManager($repo, new TaskChangeRecorder()))->update($task, $data, $user);
            self::fail('A stale form must be refused.');
        } catch (TaskChangedException $changed) {
            self::assertSame(1, $changed->currentRevision);
        }
        self::assertSame('Old', $task->getTitle());
    }

    public function testTheDigestFollowsTheFormFieldsOnly(): void
    {
        [$task] = $this->task();
        $before = TaskContentDigest::of($task);
        $task->setPosition(4);
        self::assertSame($before, TaskContentDigest::of($task), 'a position is not content');
        $task->setTags(['urgent']);
        self::assertNotSame($before, TaskContentDigest::of($task));
    }

    /**
     * @return array{Task, TestUser}
     */
    private function task(): array
    {
        $user  = new TestUser('1', 'dev@example.com');
        $board = new TaskBoard('Demo', 'demo', $user);
        $todo  = new BoardColumn($board, 'Todo', 0);
        $board->addColumn($todo);

        return [new Task($board, 'Old', $user, column: $todo), $user];
    }
}
