<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Tests\Unit\Sync;

use DateTimeImmutable;
use Nowo\TaskBoardBundle\Entity\Task;
use Nowo\TaskBoardBundle\Entity\TaskBoard;
use Nowo\TaskBoardBundle\Entity\TaskComment;
use Nowo\TaskBoardBundle\Enum\TaskChangeType;
use Nowo\TaskBoardBundle\Enum\TaskLinkType;
use Nowo\TaskBoardBundle\Event\BoardAccessCheckEvent;
use Nowo\TaskBoardBundle\Sync\Dto\SyncCursor;
use Nowo\TaskBoardBundle\Sync\Dto\SyncMode;
use Nowo\TaskBoardBundle\Sync\Dto\SyncReport;
use Nowo\TaskBoardBundle\Sync\Dto\SyncStatus;
use Nowo\TaskBoardBundle\Sync\Entity\TaskBoardConnection;
use Nowo\TaskBoardBundle\Sync\Entity\TaskSyncAttachment;
use Nowo\TaskBoardBundle\Sync\Entity\TaskSyncComment;
use Nowo\TaskBoardBundle\Sync\Entity\TaskSyncItem;
use Nowo\TaskBoardBundle\Sync\Entity\TaskSyncRun;
use Nowo\TaskBoardBundle\Tests\Stub\TestUser;
use PHPUnit\Framework\TestCase;

use function strlen;

/**
 * Since 1.23: task archive, the sync entities and the new enum / event members.
 */
final class SyncEntitiesTest extends TestCase
{
    public function testATaskIsArchivedAndRestored(): void
    {
        $task = new Task(new TaskBoard('B', 'b', new TestUser('1', 'one')), 'T', new TestUser('1', 'one'));
        self::assertFalse($task->isArchived());
        self::assertSame($task, $task->restore(), 'restoring an active task does nothing');
        $task->archive(Task::ARCHIVE_REASON_REMOTE);
        $at = $task->getArchivedAt();
        $task->archive('anything');
        self::assertSame($at, $task->getArchivedAt(), 'the first archive stays');
        self::assertSame(Task::ARCHIVE_REASON_REMOTE, $task->getArchiveReason());
        $task->restore();
        self::assertNull($task->getArchiveReason());
        $task->archive('other');
        self::assertSame(Task::ARCHIVE_REASON_USER, $task->getArchiveReason());

        self::assertNotContains(TaskLinkType::Attachment, TaskLinkType::userChoices());
        self::assertSame('sync_conflict', TaskChangeType::SyncConflict->value);
        $event = new BoardAccessCheckEvent(new TestUser('1', 'one'), new TaskBoard('B', 'b', new TestUser('1', 'one')), true, BoardAccessCheckEvent::ACTION_MANAGE_CONNECTION);
        self::assertSame('manage_connection', $event->getAction());
        self::assertSame('view', (new BoardAccessCheckEvent(new TestUser('1', 'one'), new TaskBoard('B', 'b', new TestUser('1', 'one'))))->getAction());
    }

    public function testTheSyncEntities(): void
    {
        $user       = new TestUser('1', 'one');
        $board      = new TaskBoard('B', 'b', $user);
        $task       = new Task($board, 'T', $user);
        $connection = new TaskBoardConnection($board, 'clickup', 'clickup', 'conn-1', 'L1', str_repeat('x', 300), $user, ['S'], ['importComments' => false, 'fallbackColumnId' => 'col']);

        self::assertNotSame('', $connection->getId());
        self::assertSame(255, strlen($connection->getContainerLabel()));
        self::assertSame([$board, 'clickup', 'clickup', 'conn-1', 'L1', ['S'], $user], [$connection->getBoard(), $connection->getProvider(), $connection->getImportScope(), $connection->getConnectionRef(), $connection->getContainerId(), $connection->getContainerPath(), $connection->getCreatedBy()]);
        self::assertFalse($connection->option('importComments', true));
        self::assertTrue($connection->option('importAttachments', true));
        self::assertSame('col', $connection->fallbackColumnId());
        self::assertNull($connection->setOptions([])->fallbackColumnId());
        self::assertNull($connection->getCursor());
        $connection->setCursor(new SyncCursor('r'));
        self::assertSame('r', $connection->getCursor()?->runId);
        $connection->markRun(new DateTimeImmutable('@10'))->markSuccess(new DateTimeImmutable('@20'), new DateTimeImmutable('@15'));
        $connection->markSuccess(new DateTimeImmutable('@30'), new DateTimeImmutable('@5'));
        self::assertSame([10, 30, 15], [$connection->getLastRunAt()?->getTimestamp(), $connection->getLastSuccessAt()?->getTimestamp(), $connection->getWatermark()?->getTimestamp()]);
        self::assertInstanceOf(DateTimeImmutable::class, $connection->getCreatedAt());
        self::assertNull($connection->getRunningRunId());
        self::assertNull($connection->getHeartbeatAt());

        $item = new TaskSyncItem($connection, $board->getId(), $task, 'x1', new DateTimeImmutable('@1'));
        self::assertNotSame('', $item->getId());
        self::assertSame([$connection, $board->getId(), 'x1'], [$item->getConnection(), $item->getBoardId(), $item->getExternalId()]);
        self::assertNull($item->baseline('title'));
        self::assertSame(['r', 'l'], $item->setBaseline('title', 'r', 'l')->baseline('title'));
        self::assertNull($item->conflictHash('title'));
        self::assertSame('h', $item->setConflictHash('title', 'h')->conflictHash('title'));
        self::assertNull($item->setConflictHash('title', null)->conflictHash('title'));
        self::assertFalse($item->isTombstone());
        self::assertTrue($item->setTask(null)->isTombstone());
        self::assertSame('run', $item->setLastSeenRunId('run')->getLastSeenRunId());
        self::assertNotNull($item->setRemoteArchivedAt(new DateTimeImmutable())->getRemoteArchivedAt());

        $comment = new TaskComment($task, $user, 'Body');
        $synced  = new TaskSyncComment($item, $comment, 'c1', 'b', 'l');
        self::assertSame([$item, $comment, 'c1', 'b', 'l'], [$synced->getItem(), $synced->getComment(), $synced->getRemoteId(), $synced->getBodyHash(), $synced->getLocalHash()]);
        self::assertSame(['b2', 'l2'], [$synced->written('b2', 'l2')->getBodyHash(), $synced->getLocalHash()]);

        $file = new TaskSyncAttachment($item, $task, 'f', 3, new DateTimeImmutable('@5'), 'ref', '/files/ref', 'a.png', 'image/png', 3, 'sha');
        self::assertTrue($file->matches(3, new DateTimeImmutable('@5')));
        self::assertFalse($file->matches(4, new DateTimeImmutable('@5')));
        self::assertSame(['f', $task, null], [$file->getRemoteId(), $file->getTask(), $file->getLink()]);
        self::assertSame('/files/ref', $file->stored()->url);
        self::assertSame(36, strlen($file->getId()));
        self::assertSame($item, $file->getItem());
        self::assertSame(36, strlen($synced->getId()));

        $run = new TaskSyncRun('run-1', $connection, $board->getId(), $user, 'manual', 'initial', false, new DateTimeImmutable('@1'));
        self::assertSame(['run-1', $connection, $board->getId(), $user, 'manual', 'initial', false], [$run->getId(), $run->getConnection(), $run->getBoardId(), $run->getActor(), $run->getTrigger(), $run->getMode(), $run->isDryRun()]);
        self::assertSame(1, $run->getStartedAt()->getTimestamp());
        self::assertGreaterThan(0, $run->getRunOrder());
        self::assertNull($run->getReport());
        self::assertSame(SyncStatus::Running, $run->getStatus());
        self::assertFalse($run->isCancelRequested());
        self::assertTrue($run->requestCancel()->isCancelRequested());
        $run->record(new SyncReport('run-1', 'b', 'clickup', SyncMode::Full, SyncStatus::Paused), new DateTimeImmutable('@2'));
        self::assertNull($run->getFinishedAt());
        $run->record(new SyncReport('run-1', 'b', 'clickup', SyncMode::Full, SyncStatus::Succeeded), new DateTimeImmutable('@3'));
        self::assertSame(3, $run->getFinishedAt()?->getTimestamp());
        self::assertSame(3, $run->getHeartbeatAt()?->getTimestamp());
        self::assertSame('full', $run->getMode());
        self::assertSame(36, strlen(TaskSyncRun::newId()));
    }
}
