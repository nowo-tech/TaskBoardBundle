<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Tests\Functional\Sync;

use Nowo\TaskBoardBundle\Entity\Task;
use Nowo\TaskBoardBundle\Sync\Dto\SyncError;
use Nowo\TaskBoardBundle\Sync\Dto\SyncReport;
use Nowo\TaskBoardBundle\Sync\Dto\SyncStatus;
use Nowo\TaskBoardBundle\Sync\Provider\RemoteChecklist;
use Nowo\TaskBoardBundle\Sync\Provider\RemoteChecklistItem;
use Nowo\TaskBoardBundle\Tests\Functional\App\TestSyncLimitChecker;

use function array_map;
use function array_replace_recursive;
use function json_encode;

/**
 * Since 1.25 (audit FP-L1): checklist subtasks count against `sync.checklists.max_items_per_run` and the host's
 * `SyncLimitCheckerInterface`, like tasks. What is held back is created by a later run.
 */
final class SyncChecklistLimitTest extends SyncPeopleFunctionalTestCase
{
    protected static function syncConfig(): array
    {
        return array_replace_recursive(parent::syncConfig(), ['sync' => ['checklists' => ['max_items_per_run' => 2]]]);
    }

    public function testTheRunCapAndTheHostQuotaHoldChecklistSubtasksBack(): void
    {
        $this->workspace();
        $board = $this->boardWithColumns('To do', 'Done');
        $items = [new RemoteChecklistItem('i1', 'One', 0), new RemoteChecklistItem('i2', 'Two', 1), new RemoteChecklistItem('i3', 'Three', 2)];
        $this->put($this->row('t1', 'Parent', 1, checklists: [new RemoteChecklist('cl1', 'Steps', 0, $items)]));

        $report = $this->sync($board);
        self::assertSame(SyncStatus::Succeeded, $report->status, (string) json_encode($report->toArray()));
        self::assertSame(2, $report->stat('checklist.created'), 'capped at max_items_per_run');
        self::assertContains('checklist_items_capped', $this->codes($report));
        self::assertContains([2, 0], TestSyncLimitChecker::$calls, 'the host is asked for the subtasks it gets');
        self::assertSame(3, $this->rows(Task::class));

        // The host refuses: nothing new, the parent and the existing items stay.
        TestSyncLimitChecker::$refuseTasks = true;
        $this->put($this->row('t1', 'Parent', 2, checklists: [new RemoteChecklist('cl1', 'Steps', 0, $items)]));
        $report = $this->sync($board);
        self::assertSame(0, $report->stat('checklist.created'));
        self::assertContains('checklist_items_refused', $this->codes($report));
        self::assertSame(3, $this->rows(Task::class));

        // Allowed again: the next run creates the held-back item.
        TestSyncLimitChecker::$refuseTasks = false;
        $this->put($this->row('t1', 'Parent', 3, checklists: [new RemoteChecklist('cl1', 'Steps', 0, $items)]));
        $report = $this->sync($board);
        self::assertSame(1, $report->stat('checklist.created'));
        self::assertNotContains('checklist_items_capped', $this->codes($report));
        $this->em->clear();
        self::assertSame('Parent', $this->taskTitled('Three')->getParent()?->getTitle());
    }

    /**
     * @return list<string>
     */
    private function codes(SyncReport $report): array
    {
        return array_map(static fn (SyncError $w): string => $w->code, $report->warnings);
    }
}
