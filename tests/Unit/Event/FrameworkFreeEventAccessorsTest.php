<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Tests\Unit\Event;

use Nowo\TaskBoardBundle\Entity\ColumnSet;
use Nowo\TaskBoardBundle\Entity\TaskComment;
use Nowo\TaskBoardBundle\Event\BoardAccessCheckEvent;
use Nowo\TaskBoardBundle\Event\BoardCreatedEvent;
use Nowo\TaskBoardBundle\Event\BoardUpdatedEvent;
use Nowo\TaskBoardBundle\Event\ColumnSetAccessCheckEvent;
use Nowo\TaskBoardBundle\Event\ColumnSetQueryEvent;
use Nowo\TaskBoardBundle\Event\TaskAccessCheckEvent;
use Nowo\TaskBoardBundle\Event\TaskBoardQuotaCheckEvent;
use Nowo\TaskBoardBundle\Event\TaskCommentEvent;
use Nowo\TaskBoardBundle\Event\TaskCreatedEvent;
use Nowo\TaskBoardBundle\Event\TaskMentionEvent;
use Nowo\TaskBoardBundle\Sync\Dto\SyncError;
use Nowo\TaskBoardBundle\Sync\Dto\SyncMode;
use Nowo\TaskBoardBundle\Sync\Dto\SyncReport;
use Nowo\TaskBoardBundle\Sync\Dto\SyncStatus;
use Nowo\TaskBoardBundle\Sync\Dto\SyncTrigger;
use Nowo\TaskBoardBundle\Sync\Event\BoardConnectionEvent;
use Nowo\TaskBoardBundle\Sync\Event\SyncFailedEvent;
use Nowo\TaskBoardBundle\Sync\Event\SyncFinishedEvent;
use Nowo\TaskBoardBundle\Sync\Event\SyncPageDoneEvent;
use Nowo\TaskBoardBundle\Sync\Event\SyncStartedEvent;
use Nowo\TaskBoardBundle\Sync\Event\TaskRemoteArchivedEvent;
use Nowo\TaskBoardBundle\Sync\Event\TaskRemoteRestoredEvent;
use Nowo\TaskBoardBundle\Tests\Unit\Port\PortFixtures;
use PHPUnit\Framework\TestCase;
use stdClass;

/**
 * Since 2.15.0 (REQ-ARCH-001 rules 1 and 4): every event that carried a bundle entity also answers with ids and
 * framework-free snapshots; the entity members stay (deprecated) and return the same objects.
 */
final class FrameworkFreeEventAccessorsTest extends TestCase
{
    use PortFixtures;

    public function testAccessCheckEvents(): void
    {
        $board = $this->board();
        $task  = $this->task($board);
        $user  = $this->user();

        $boardCheck = new BoardAccessCheckEvent($user, $board);
        self::assertSame([$board->getId(), 'acme', $board->getId(), 'u-7'], [$boardCheck->getBoardId(), $boardCheck->getTenantKey(), $boardCheck->getBoardSnapshot()->id, $boardCheck->getSubjectId()]);
        self::assertSame($board, $boardCheck->getBoard());
        self::assertNull((new BoardAccessCheckEvent(new stdClass(), $board))->getSubjectId());

        $taskCheck = new TaskAccessCheckEvent($user, $task);
        self::assertSame([$task->getId(), $board->getId(), 'acme', $task->getId(), 'u-7'], [$taskCheck->getTaskId(), $taskCheck->getBoardId(), $taskCheck->getTenantKey(), $taskCheck->getTaskSnapshot()->id, $taskCheck->getSubjectId()]);

        $set      = new ColumnSet('Kanban', $user, 'acme');
        $setCheck = new ColumnSetAccessCheckEvent($user, $set);
        self::assertSame([$set->getId(), 'acme', 'u-7', 'u-7'], [$setCheck->getColumnSetId(), $setCheck->getColumnSetScopeKey(), $setCheck->getColumnSetCreatorId(), $setCheck->getSubjectId()]);

        $query = new ColumnSetQueryEvent($user, $board);
        self::assertSame([$board->getId(), $board->getId(), 'u-7'], [$query->getBoardId(), $query->getBoardSnapshot()?->id, $query->getSubjectId()]);
        $outside = new ColumnSetQueryEvent($user, null, 'acme');
        self::assertSame([null, null], [$outside->getBoardId(), $outside->getBoardSnapshot()]);
    }

    public function testQuotaAndBoardEvents(): void
    {
        $board = $this->board();
        $user  = $this->user();

        $quota = new TaskBoardQuotaCheckEvent(TaskBoardQuotaCheckEvent::ACTION_TASK_CREATE, 'acme', $board, 1, $user);
        self::assertSame([$board->getId(), $board->getId(), 'u-7'], [$quota->getBoardId(), $quota->getBoardSnapshot()?->id, $quota->getActorId()]);
        $creating = new TaskBoardQuotaCheckEvent(TaskBoardQuotaCheckEvent::ACTION_BOARD_CREATE, 'acme', null, 1, new stdClass());
        self::assertSame([null, null, null], [$creating->getBoardId(), $creating->getBoardSnapshot(), $creating->getActorId()]);

        self::assertSame('roadmap', (new BoardCreatedEvent($board, $user))->getBoardSnapshot()->slug);
        self::assertSame('roadmap', (new BoardUpdatedEvent($board, $user, ['name']))->getBoardSnapshot()->slug);
    }

    public function testSyncEvents(): void
    {
        $board  = $this->board();
        $user   = $this->user();
        $report = new SyncReport('run-1', $board->getId(), 'clickup', SyncMode::Initial, SyncStatus::Running);

        $events = [
            new BoardConnectionEvent($board, 'clickup', 'l-1', 'List', 'c-1', $user),
            new SyncStartedEvent($board, 'run-1', 'clickup', SyncMode::Initial, SyncTrigger::Manual, $user, false),
            new SyncFailedEvent($board, new SyncError('boom'), $report),
            new SyncFinishedEvent($board, $report),
            new SyncPageDoneEvent($board, $report),
        ];
        foreach ($events as $event) {
            self::assertSame([$board->getId(), 'acme', $board->getId()], [$event->getBoardId(), $event->getTenantKey(), $event->getBoardSnapshot()->id]);
        }
        self::assertSame('u-7', $events[0]->getActorId());
        self::assertSame('u-7', $events[1]->getActorId());

        $task = $this->task($board);
        foreach ([new TaskRemoteArchivedEvent($task, 'clickup', 'x-1', 'run-1'), new TaskRemoteRestoredEvent($task, 'clickup', 'x-1', 'run-1')] as $event) {
            self::assertSame([$task->getId(), $board->getId(), 'acme', $task->getId()], [$event->getTaskId(), $event->getBoardId(), $event->getTenantKey(), $event->getTaskSnapshot()->id]);
        }
    }

    public function testTaskAndCommentSnapshots(): void
    {
        $task    = $this->task();
        $user    = $this->user();
        $comment = new TaskComment($task, $user, 'Hi');

        self::assertSame($task->getId(), (new TaskCreatedEvent($task, $user))->getTaskSnapshot()->id);
        self::assertSame('Hi', (new TaskCommentEvent($user, $comment, TaskCommentEvent::CREATED))->getCommentSnapshot()->body);
        self::assertSame($comment->getId(), (new TaskMentionEvent($user, $comment, []))->getCommentSnapshot()->id);
    }
}
