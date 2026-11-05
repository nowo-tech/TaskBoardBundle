<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Tests\Unit\Entity;

use DateTimeImmutable;
use Nowo\TaskBoardBundle\Entity\Task;
use Nowo\TaskBoardBundle\Entity\TaskBoard;
use Nowo\TaskBoardBundle\Entity\TaskLink;
use Nowo\TaskBoardBundle\Entity\TaskTimeEntry;
use Nowo\TaskBoardBundle\Enum\TaskLinkType;
use PHPUnit\Framework\TestCase;
use stdClass;

use function mb_strlen;
use function str_repeat;

/**
 * Since 1.23.2: a time entry keeps snapshots of its task and outlives it.
 */
final class TaskTimeEntryTest extends TestCase
{
    public function testSnapshotsFollowTheTaskUntilItIsRemoved(): void
    {
        $user  = new stdClass();
        $board = new TaskBoard('Board', 'board', $user);
        $task  = new Task($board, 'Native', $user);
        $entry = new TaskTimeEntry($task, $user, 25, new DateTimeImmutable('2026-11-05'));
        $task->addTimeEntry($entry);

        self::assertFalse($entry->isDetached());
        self::assertSame($task, $entry->getTask());
        self::assertSame('Native', $entry->getTaskTitleSnapshot());
        self::assertSame($board->getId(), $entry->getBoardIdSnapshot());
        self::assertNull($entry->getTaskExternalId());

        // An unchanged title does not touch the snapshots; a rename does.
        $task->setTitle('Native');
        $task->addLink(new TaskLink($task, TaskLinkType::Url, 'https://example.com/1', null, 'ext-1', importSource: 'jira'));
        self::assertNull($entry->getTaskExternalId());
        $task->setTitle(str_repeat('x', 300));
        self::assertSame(Task::TITLE_MAX, mb_strlen((string) $entry->getTaskTitleSnapshot()));
        self::assertSame('ext-1', $entry->getTaskExternalId());
        $task->setTitle('Renamed');
        self::assertSame('Renamed', $entry->getTaskTitle());
        self::assertSame($board->getId(), $entry->getBoardId());

        $task->detachTimeEntries();

        self::assertTrue($entry->isDetached());
        self::assertNull($entry->getTask());
        self::assertSame('Renamed', $entry->getTaskTitle());
        self::assertSame($board->getId(), $entry->getBoardId());
        self::assertSame('ext-1', $entry->getTaskExternalId());
        self::assertSame(25, $entry->getMinutes());

        // Once detached, later changes of the old task never reach the entry.
        $entry->refreshTaskSnapshot();
        $task->setTitle('After');
        self::assertSame('Renamed', $entry->getTaskTitle());
    }

    public function testTheExternalIdComesFromAnImportLinkOnly(): void
    {
        $user = new stdClass();
        $task = new Task(new TaskBoard('Board', 'board', $user), 'Task', $user);
        $task->addLink(new TaskLink($task, TaskLinkType::Url, 'https://example.com/a', null, 'not-imported'));
        $task->addLink(new TaskLink($task, TaskLinkType::Url, 'https://example.com/b', importSource: 'trello'));
        self::assertNull($task->getExternalId());

        $task->addLink(new TaskLink($task, TaskLinkType::Url, 'https://example.com/c', null, 'card-9', importSource: 'trello'));
        self::assertSame('card-9', $task->getExternalId());
    }
}
