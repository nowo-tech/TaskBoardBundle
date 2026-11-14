<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Tests\Functional\Sync;

use Nowo\TaskBoardBundle\Entity\Task;
use Nowo\TaskBoardBundle\Sync\Dto\SyncError;
use Nowo\TaskBoardBundle\Sync\Dto\SyncReport;
use Nowo\TaskBoardBundle\Sync\Dto\SyncStatus;
use Nowo\TaskBoardBundle\Sync\Entity\TaskSyncItem;
use Nowo\TaskBoardBundle\Sync\Provider\RemoteChecklist;
use Nowo\TaskBoardBundle\Sync\Provider\RemoteChecklistItem;
use Nowo\TaskBoardBundle\Tests\Functional\FoldsKeyColumns;

use function array_map;
use function json_encode;
use function sprintf;

/**
 * Audit OM TB-M1 (REQ-SEC-006): on a `task_board_sync_items.external_id` that still folds (before the UPGRADING ALTER), a
 * provider id whose item the database finds only as a look-alike (`T1` for `t1`) is skipped and reported. A new item
 * would break the folding unique key, fail the page and close the entity manager for the rest of a worker's life.
 */
final class SyncLookAlikeIdsTest extends SyncPeopleFunctionalTestCase
{
    use FoldsKeyColumns;

    public function testALookAlikeItemIsSkippedInsteadOfBreakingTheUniqueKey(): void
    {
        $this->foldKeyColumns($this->em);
        $this->owner = $this->user('owner@example.com');
        $this->workspace();
        $board = $this->boardWithColumns('To do', 'Done');
        $steps = [new RemoteChecklist('cl1', 'Steps', 0, [new RemoteChecklistItem('i1', 'One', 0)])];
        $this->put($this->row('t1', 'Parent', 1, checklists: $steps));
        $report = $this->sync($board);
        self::assertSame(SyncStatus::Succeeded, $report->status, (string) json_encode($report->toArray()));
        self::assertSame(2, $this->rows(Task::class));
        $table = $this->em->getClassMetadata(TaskSyncItem::class)->getTableName();
        $db    = $this->em->getConnection();

        // The checklist item's row is a look-alike's now: the next run does not add a second one.
        $db->executeStatement(sprintf("UPDATE %s SET external_id = 'T1#I1' WHERE external_id = 't1#i1'", $table));
        $this->put($this->row('t1', 'Parent', 2, checklists: $steps));
        $report = $this->sync($board);
        self::assertSame(SyncStatus::Succeeded, $report->status, (string) json_encode($report->toArray()));
        self::assertSame(1, $report->stat('skipLookAlike'));
        self::assertContains('external_id_look_alike', $this->codes($report));
        self::assertSame(2, $this->rows(Task::class));
        self::assertTrue($this->em->isOpen());

        // The task's own row too.
        $db->executeStatement(sprintf("UPDATE %s SET external_id = 'T1' WHERE external_id = 't1'", $table));
        $this->put($this->row('t1', 'Parent changed', 3, checklists: $steps));
        $report = $this->sync($board);
        self::assertSame(SyncStatus::Succeeded, $report->status, (string) json_encode($report->toArray()));
        self::assertSame(1, $report->stat('skipLookAlike'));
        self::assertSame(2, $this->rows(Task::class));
        self::assertSame(2, (int) $db->fetchOne(sprintf('SELECT COUNT(*) FROM %s', $table)));
        self::assertTrue($this->em->isOpen());
        $this->em->clear();
        self::assertSame('Parent', $this->taskTitled('Parent')->getTitle(), 'the look-alike\'s task is not written');
    }

    /**
     * @return list<string>
     */
    private function codes(SyncReport $report): array
    {
        return array_map(static fn (SyncError $w): string => $w->code, $report->warnings);
    }
}
