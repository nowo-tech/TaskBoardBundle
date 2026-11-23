<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Tests\Functional\Sync;

use DateTimeImmutable;
use Nowo\TaskBoardBundle\Entity\Task;
use Nowo\TaskBoardBundle\Enum\TaskPriority;
use Nowo\TaskBoardBundle\Import\Dto\ImportedTaskDto;
use Nowo\TaskBoardBundle\Sync\Dto\SyncStatus;
use Nowo\TaskBoardBundle\Sync\Entity\TaskSyncItem;
use Nowo\TaskBoardBundle\Sync\Provider\RemoteTask;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;

use function json_encode;

/**
 * REQ-DI-001 (2.21.1): the sync writer reads the time from the injected clock (`Psr\Clock\ClockInterface`, the
 * framework's `clock` service), never from a bare `new DateTimeImmutable()` / `time()`: the remote archive date and the
 * "not in the future" bound of a source's creation date follow a mocked clock.
 */
final class SyncClockTest extends SyncFunctionalTestCase
{
    use ClockSensitiveTrait;

    private const NOW = '2031-05-06 07:08:09 UTC';

    public function testTheRemoteArchiveDateIsTheClocksTime(): void
    {
        self::mockTime(self::NOW);
        $board = $this->board(columns: ['To do', 'Done']);
        $this->put($this->remote('a1', 'Archived at the source', archived: true));

        $report = $this->sync($board);

        self::assertSame(SyncStatus::Succeeded, $report->status, 'errors: ' . json_encode($report->errors));
        $this->em->clear();
        $item = $this->em->getRepository(TaskSyncItem::class)->findOneBy(['externalId' => 'a1']);
        self::assertInstanceOf(TaskSyncItem::class, $item);
        self::assertEquals(new DateTimeImmutable(self::NOW), $item->getRemoteArchivedAt());
    }

    public function testACreationDateIsPlausibleUpToTheClocksTime(): void
    {
        // A source date a year before the mocked "now" is in the future for the real clock, and kept.
        self::mockTime(self::NOW);
        $board   = $this->board(columns: ['To do', 'Done']);
        $created = new DateTimeImmutable('2030-05-06 07:08:09 UTC');
        $this->put(new RemoteTask(
            task: new ImportedTaskDto(title: 'Created by the source', externalId: 'c1', status: 'To do', priority: TaskPriority::Normal, createdAt: $created, hasPriority: false),
            remoteUpdatedAt: new DateTimeImmutable('@1790000001'),
        ));

        $report = $this->sync($board);

        self::assertSame(SyncStatus::Succeeded, $report->status, 'errors: ' . json_encode($report->errors));
        $this->em->clear();
        $task = $this->em->getRepository(Task::class)->findOneBy(['title' => 'Created by the source']);
        self::assertInstanceOf(Task::class, $task);
        self::assertEquals($created, $task->getCreatedAt());
    }
}
