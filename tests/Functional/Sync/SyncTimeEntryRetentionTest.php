<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Tests\Functional\Sync;

use DateTimeImmutable;
use Nowo\TaskBoardBundle\Entity\TaskTimeEntry;
use Nowo\TaskBoardBundle\Service\TaskTimeLogger;
use Nowo\TaskBoardBundle\Sync\TaskBoardSyncService;

/**
 * Since 1.23.2: a provider sync never deletes tracked time — a task the provider no longer lists is archived (its
 * entries stay attached), a remote rename refreshes the entries' title snapshot, and unlinking the board removes the
 * sync state only.
 */
final class SyncTimeEntryRetentionTest extends SyncFunctionalTestCase
{
    public function testRemoteDeletionsRenamesAndUnlinkKeepTheTime(): void
    {
        $board = $this->board();
        $this->put($this->remote('t1', 'Synced task', 1));
        $this->sync($board);

        $logger = static::getContainer()->get(TaskTimeLogger::class);
        self::assertInstanceOf(TaskTimeLogger::class, $logger);
        $entry = $logger->log($this->taskTitled('Synced task'), $this->owner, 45, new DateTimeImmutable('2026-11-04'));
        self::assertSame('t1', $entry->getTaskExternalId());

        // A remote rename follows the provider and refreshes the snapshot.
        $this->put($this->remote('t1', 'Renamed remotely', 2));
        $this->sync($board);
        self::assertSame('Renamed remotely', $this->reloadEntry($entry->getId())->getTaskTitleSnapshot());

        // Gone remotely: archived, never deleted — the entry keeps its task.
        $this->provider->removeTask(self::LIST, 't1');
        self::assertSame(1, $this->sync($board, allowMassArchive: true)->archived);
        $kept = $this->reloadEntry($entry->getId());
        self::assertSame(45, $kept->getMinutes());
        self::assertTrue($kept->getTask()?->isArchived());

        // Unlinking removes the connection and its items only.
        $service = static::getContainer()->get(TaskBoardSyncService::class);
        self::assertInstanceOf(TaskBoardSyncService::class, $service);
        $service->unlink($this->freshBoard($board), $this->owner);
        $this->em->clear();
        self::assertNull($this->connectionOf($board));
        self::assertFalse($this->reloadEntry($entry->getId())->isDetached());
    }

    private function reloadEntry(string $id): TaskTimeEntry
    {
        $this->em->clear();
        $entry = $this->em->find(TaskTimeEntry::class, $id);
        self::assertInstanceOf(TaskTimeEntry::class, $entry);

        return $entry;
    }
}
