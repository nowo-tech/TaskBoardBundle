<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Tests\Unit\Port;

use DateTimeImmutable;
use Nowo\TaskBoardBundle\Entity\BoardColumn;
use Nowo\TaskBoardBundle\Entity\Task;
use Nowo\TaskBoardBundle\Entity\TaskBoard;
use Nowo\TaskBoardBundle\Entity\TaskChangeHistory;
use Nowo\TaskBoardBundle\Entity\TaskComment;
use Nowo\TaskBoardBundle\Enum\TaskChangeType;
use Nowo\TaskBoardBundle\Enum\TaskPriority;
use Nowo\TaskBoardBundle\Sync\Entity\TaskSyncDirectory;
use Nowo\TaskBoardBundle\Tests\Stub\TestUser;

/**
 * Entities of the port tests: a board in tenant "acme", a task with a column and a parent, a comment and a history row.
 */
trait PortFixtures
{
    private function user(): TestUser
    {
        return new TestUser('u-7', 'ana@example.com');
    }

    private function board(): TaskBoard
    {
        $board = new TaskBoard('Roadmap', 'roadmap', $this->user());
        $board->setTenantKey('acme');

        return $board;
    }

    private function task(?TaskBoard $board = null): Task
    {
        $board ??= $this->board();
        $parent = new Task($board, 'Parent', null);

        return new Task($board, 'Ship it', $this->user(), new BoardColumn($board, 'Doing'), $parent, null, TaskPriority::High, 0, null, new DateTimeImmutable('2026-12-01'), ['b', 'a']);
    }

    private function comment(): TaskComment
    {
        return new TaskComment($this->task(), $this->user(), 'Looks good', [], null, 'rule:42');
    }

    private function change(): TaskChangeHistory
    {
        return new TaskChangeHistory($this->task(), $this->user(), TaskChangeType::Title, 'old', 'new', 'title', 'rule:7');
    }

    private function directory(): TaskSyncDirectory
    {
        return new TaskSyncDirectory('clickup', 'project:1', 'ws-1');
    }
}
