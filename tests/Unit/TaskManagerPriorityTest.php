<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Tests\Unit;

use Nowo\TaskBoardBundle\Entity\BoardColumn;
use Nowo\TaskBoardBundle\Entity\Task;
use Nowo\TaskBoardBundle\Entity\TaskBoard;
use Nowo\TaskBoardBundle\Enum\TaskPriority;
use Nowo\TaskBoardBundle\Repository\TaskRepositoryInterface;
use Nowo\TaskBoardBundle\Service\TaskChangeRecorder;
use Nowo\TaskBoardBundle\Service\TaskManager;
use PHPUnit\Framework\TestCase;
use stdClass;

final class TaskManagerPriorityTest extends TestCase
{
    public function testUpdatePriorityPersistsNewValue(): void
    {
        $board  = new TaskBoard('Demo', 'demo', new stdClass());
        $column = new BoardColumn($board, 'To do', 0);
        $board->addColumn($column);

        $task = new Task($board, 'Ship feature', new stdClass(), column: $column, priority: TaskPriority::Normal);
        $user = new stdClass();

        $repository = $this->createMock(TaskRepositoryInterface::class);
        $repository->expects(self::once())
            ->method('save')
            ->with(self::callback(static fn (Task $saved): bool => $saved->getPriority() === TaskPriority::Urgent));

        $manager = new TaskManager($repository, new TaskChangeRecorder());
        $manager->updatePriority($task, TaskPriority::Urgent, $user);

        self::assertSame(TaskPriority::Urgent, $task->getPriority());
        self::assertCount(1, $task->getChangeHistory());
    }
}
