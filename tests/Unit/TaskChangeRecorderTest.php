<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Tests\Unit;

use Nowo\TaskBoardBundle\Entity\BoardColumn;
use Nowo\TaskBoardBundle\Entity\Task;
use Nowo\TaskBoardBundle\Entity\TaskBoard;
use Nowo\TaskBoardBundle\Enum\TaskChangeType;
use Nowo\TaskBoardBundle\Enum\TaskPriority;
use Nowo\TaskBoardBundle\Service\TaskChangeRecorder;
use PHPUnit\Framework\TestCase;
use stdClass;

final class TaskChangeRecorderTest extends TestCase
{
    public function testRecordStoresContext(): void
    {
        $board = new TaskBoard('Demo', 'demo', new stdClass());
        $task  = new Task($board, 'Work', new stdClass());
        $user  = new stdClass();

        (new TaskChangeRecorder())->record($task, $user, TaskChangeType::Title, 'A', 'B', 'ctx');

        self::assertSame('ctx', $task->getChangeHistory()->first()->getContext());
    }

    public function testRecordCreatedAddsHistoryEntry(): void
    {
        $board = new TaskBoard('Demo', 'demo', new stdClass());
        $task  = new Task($board, 'Ship feature', new stdClass());
        $user  = new stdClass();

        $recorder = new TaskChangeRecorder();
        $recorder->recordCreated($task, $user);

        self::assertCount(1, $task->getChangeHistory());
        $entry = $task->getChangeHistory()->first();
        self::assertSame(TaskChangeType::Created, $entry->getChangeType());
        self::assertSame('Ship feature', $entry->getNewValue());
        self::assertSame($user, $entry->getUser());
    }

    public function testRecordIfChangedSkipsUnchangedValues(): void
    {
        $board = new TaskBoard('Demo', 'demo', new stdClass());
        $task  = new Task($board, 'Ship feature', new stdClass(), priority: TaskPriority::Normal);
        $user  = new stdClass();

        $recorder = new TaskChangeRecorder();
        $recorder->recordIfChanged($task, $user, TaskChangeType::Priority, TaskPriority::Normal, TaskPriority::Normal);

        self::assertCount(0, $task->getChangeHistory());
    }

    public function testRecordIfChangedStoresColumnNames(): void
    {
        $board    = new TaskBoard('Demo', 'demo', new stdClass());
        $todo     = new BoardColumn($board, 'To do', 0);
        $progress = new BoardColumn($board, 'In progress', 1);
        $board->addColumn($todo)->addColumn($progress);

        $task = new Task($board, 'Ship feature', new stdClass(), column: $todo);
        $user = new stdClass();

        $recorder = new TaskChangeRecorder();
        $recorder->recordIfChanged($task, $user, TaskChangeType::Column, $todo, $progress);

        self::assertCount(1, $task->getChangeHistory());
        $entry = $task->getChangeHistory()->first();
        self::assertSame('To do', $entry->getOldValue());
        self::assertSame('In progress', $entry->getNewValue());
    }
}
