<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Tests\Unit;

use Nowo\TaskBoardBundle\Entity\BoardColumn;
use Nowo\TaskBoardBundle\Entity\Task;
use Nowo\TaskBoardBundle\Entity\TaskBoard;
use Nowo\TaskBoardBundle\Repository\TaskRepositoryInterface;
use Nowo\TaskBoardBundle\Service\TaskChangeRecorder;
use Nowo\TaskBoardBundle\Service\TaskManager;
use PHPUnit\Framework\TestCase;
use stdClass;

final class TaskManagerColumnNavigationTest extends TestCase
{
    public function testMoveToNextColumnAdvancesAndMarksDoneOnLastColumn(): void
    {
        $board    = new TaskBoard('Demo', 'demo', new stdClass());
        $todo     = new BoardColumn($board, 'To do', 0);
        $progress = new BoardColumn($board, 'In progress', 1);
        $done     = new BoardColumn($board, 'Done', 2);
        $board->addColumn($todo)->addColumn($progress)->addColumn($done);

        $task = new Task($board, 'Ship feature', new stdClass(), column: $progress);
        $user = new stdClass();

        $repository = $this->createMock(TaskRepositoryInterface::class);
        $repository->expects(self::once())
            ->method('findByBoard')
            ->willReturn([$task]);
        $repository->expects(self::once())
            ->method('save')
            ->with(self::callback(static fn (Task $saved): bool => $saved->getColumn()?->getName() === 'Done'
                && $saved->isCompleted()));

        $manager = new TaskManager($repository, new TaskChangeRecorder());
        self::assertTrue($manager->moveToNextColumn($task, $user));
    }

    public function testMoveToPreviousColumnGoesBackAndClearsCompleted(): void
    {
        $board = new TaskBoard('Demo', 'demo', new stdClass());
        $todo  = new BoardColumn($board, 'To do', 0);
        $done  = new BoardColumn($board, 'Done', 1);
        $board->addColumn($todo)->addColumn($done);

        $task = new Task($board, 'Ship feature', new stdClass(), column: $done);
        $task->markCompleted();
        $user = new stdClass();

        $repository = $this->createMock(TaskRepositoryInterface::class);
        $repository->expects(self::once())
            ->method('findByBoard')
            ->willReturn([$task]);
        $repository->expects(self::once())
            ->method('save')
            ->with(self::callback(static fn (Task $saved): bool => $saved->getColumn()?->getName() === 'To do'
                && !$saved->isCompleted()));

        $manager = new TaskManager($repository, new TaskChangeRecorder());
        self::assertTrue($manager->moveToPreviousColumn($task, $user));
    }

    public function testMoveToDoneMovesToLastColumn(): void
    {
        $board = new TaskBoard('Demo', 'demo', new stdClass());
        $todo  = new BoardColumn($board, 'To do', 0);
        $done  = new BoardColumn($board, 'Done', 1);
        $board->addColumn($todo)->addColumn($done);

        $task = new Task($board, 'Ship feature', new stdClass(), column: $todo);
        $user = new stdClass();

        $repository = $this->createMock(TaskRepositoryInterface::class);
        $repository->expects(self::once())
            ->method('findByBoard')
            ->willReturn([$task]);
        $repository->expects(self::once())->method('save');

        $manager = new TaskManager($repository, new TaskChangeRecorder());
        self::assertTrue($manager->moveToDone($task, $user));
        self::assertSame('Done', $task->getColumn()?->getName());
        self::assertTrue($task->isCompleted());
    }

    public function testMoveToColumnJumpsToSelectedColumn(): void
    {
        $board    = new TaskBoard('Demo', 'demo', new stdClass());
        $todo     = new BoardColumn($board, 'To do', 0);
        $progress = new BoardColumn($board, 'In progress', 1);
        $done     = new BoardColumn($board, 'Done', 2);
        $board->addColumn($todo)->addColumn($progress)->addColumn($done);

        $task = new Task($board, 'Ship feature', new stdClass(), column: $todo);
        $user = new stdClass();

        $repository = $this->createMock(TaskRepositoryInterface::class);
        $repository->expects(self::once())
            ->method('findByBoard')
            ->willReturn([$task]);
        $repository->expects(self::once())
            ->method('save')
            ->with(self::callback(static fn (Task $saved): bool => $saved->getColumn()?->getName() === 'In progress'));

        $manager = new TaskManager($repository, new TaskChangeRecorder());
        self::assertTrue($manager->moveToColumn($task, $progress->getId(), $user));
    }

    public function testMoveToColumnReturnsFalseForCurrentColumn(): void
    {
        $board = new TaskBoard('Demo', 'demo', new stdClass());
        $todo  = new BoardColumn($board, 'To do', 0);
        $board->addColumn($todo);

        $task = new Task($board, 'Ship feature', new stdClass(), column: $todo);
        $user = new stdClass();

        $manager = new TaskManager($this->createMock(TaskRepositoryInterface::class), new TaskChangeRecorder());
        self::assertFalse($manager->moveToColumn($task, $todo->getId(), $user));
    }

    public function testColumnNavigationReflectsCurrentPosition(): void
    {
        $board    = new TaskBoard('Demo', 'demo', new stdClass());
        $todo     = new BoardColumn($board, 'To do', 0);
        $progress = new BoardColumn($board, 'In progress', 1);
        $done     = new BoardColumn($board, 'Done', 2);
        $board->addColumn($todo)->addColumn($progress)->addColumn($done);

        $task = new Task($board, 'Ship feature', new stdClass(), column: $progress);

        $manager    = new TaskManager($this->createMock(TaskRepositoryInterface::class), new TaskChangeRecorder());
        $navigation = $manager->getColumnNavigation($task);

        self::assertSame('In progress', $navigation->currentColumnName);
        self::assertSame('To do', $navigation->previousColumnName);
        self::assertSame('Done', $navigation->nextColumnName);
        self::assertTrue($navigation->canPrevious);
        self::assertTrue($navigation->canNext);
        self::assertTrue($navigation->canDone);
    }
}
