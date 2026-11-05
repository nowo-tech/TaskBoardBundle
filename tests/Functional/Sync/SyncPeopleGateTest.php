<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Tests\Functional\Sync;

use Nowo\TaskBoardBundle\Sync\Dto\SyncStatus;
use Nowo\TaskBoardBundle\Sync\Entity\TaskSyncDirectory;
use Nowo\TaskBoardBundle\Sync\Entity\TaskSyncPerson;
use Nowo\TaskBoardBundle\Sync\People\TaskSyncPeopleDirectoryService;

/**
 * Since 1.25 (§2.6, §5.1, §9): the People-step gate of a first import, and the directory pruned with its last
 * connection.
 */
final class SyncPeopleGateTest extends SyncPeopleFunctionalTestCase
{
    public function testAFirstImportWaitsForThePeopleStepWhileSomeoneIsUndecided(): void
    {
        $board = $this->boardWithColumns('To do', 'Done');
        $this->workspace([self::member('9001', 'stranger@example.test')]);
        $this->put($this->row('t1', 'Task', 1));

        $report = $this->sync($board, requirePeopleMapping: true);
        self::assertSame(SyncStatus::Refused, $report->status);
        self::assertSame('people_mapping_required', $report->refusalCode);
        self::assertSame(1, $this->rows(TaskSyncPerson::class), 'the members are stored for the People step');
        self::assertSame(0, $report->created);

        $directory = $this->em->getRepository(TaskSyncDirectory::class)->findOneBy([]);
        self::assertInstanceOf(TaskSyncDirectory::class, $directory);
        $this->directories()->markReviewed($directory, $this->owner);
        $report = $this->sync($board, requirePeopleMapping: true);
        self::assertSame(SyncStatus::Succeeded, $report->status);
        self::assertSame(1, $report->created);
    }

    public function testNobodyUndecidedOrALaterRunIsNotGated(): void
    {
        $board = $this->boardWithColumns('To do', 'Done');
        $this->workspace([]);
        $this->put($this->row('t1', 'Task', 1));
        self::assertSame(SyncStatus::Succeeded, $this->sync($board, requirePeopleMapping: true)->status);

        $this->workspace([self::member('9001', 'stranger@example.test')]);
        $this->put($this->row('t1', 'Task', 2));
        self::assertSame(SyncStatus::Succeeded, $this->sync($board, requirePeopleMapping: true)->status, 'only a first import is gated');
    }

    public function testADryRunDoesNotStorePeopleAndUnlinkPrunesTheDirectory(): void
    {
        $board = $this->boardWithColumns('To do', 'Done');
        $this->workspace([self::member('9001')]);
        $this->put($this->row('t1', 'Task', 1));
        self::assertSame(SyncStatus::Succeeded, $this->sync($board, dryRun: true)->status);
        self::assertSame(0, $this->rows(TaskSyncPerson::class));

        $this->sync($board);
        self::assertSame(1, $this->rows(TaskSyncPerson::class));
        $this->service()->unlink($this->freshBoard($board), $this->owner);
        $this->em->clear();
        self::assertSame(0, $this->rows(TaskSyncPerson::class));
        self::assertSame(0, $this->rows(TaskSyncDirectory::class));
    }

    private function directories(): TaskSyncPeopleDirectoryService
    {
        $service = self::getContainer()->get(TaskSyncPeopleDirectoryService::class);
        self::assertInstanceOf(TaskSyncPeopleDirectoryService::class, $service);

        return $service;
    }
}
