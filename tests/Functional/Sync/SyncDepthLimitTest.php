<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Tests\Functional\Sync;

use Nowo\TaskBoardBundle\Entity\Task;
use Nowo\TaskBoardBundle\Sync\Dto\SyncError;
use Nowo\TaskBoardBundle\Sync\Provider\RemoteChecklist;
use Nowo\TaskBoardBundle\Sync\Provider\RemoteChecklistItem;

use function array_map;
use function array_replace_recursive;

/**
 * Beacon spec 234, T004 (TaskBoard 2.20.0): `max_subtask_depth: 1` in a provider sync. A task deeper than the limit goes
 * under its deepest allowed ancestor, checklist items of a task at the limit are not created, and a parent change that
 * would take a subtree past the limit (through the shared port, on locked rows) keeps the task where it is: each warned
 * as `parent_too_deep`.
 */
final class SyncDepthLimitTest extends SyncPeopleFunctionalTestCase
{
    protected static function syncConfig(): array
    {
        return array_replace_recursive(parent::syncConfig(), ['max_subtask_depth' => 1]);
    }

    public function testTheSyncKeepsTheTreeWithinTheLimit(): void
    {
        $this->viewer('carol@example.test');
        $this->workspace();
        $board = $this->boardWithColumns('To do', 'Done');
        $this->put(
            $this->row('t0', 'Root', 1),
            $this->row('t1', 'Child', 2, checklists: [new RemoteChecklist('cl1', 'Steps', 0, [new RemoteChecklistItem('i1', 'Step', 0, false)])], parent: 't0'),
            $this->row('t2', 'Grandchild', 3, parent: 't1'),
            $this->row('s0', 'Subtree', 4),
            $this->row('s1', 'Subtree child', 5, parent: 's0'),
            $this->row('l0', 'Leaf', 6),
        );

        $report = $this->sync($board);

        $this->em->clear();
        self::assertSame('Root', $this->taskTitled('Grandchild')->getParent()?->getTitle());
        self::assertNull($this->em->getRepository(Task::class)->findOneBy(['title' => 'Step']), 'an item would be at depth 2');
        self::assertContains('parent_too_deep', $this->codes($report->warnings));

        // The provider moves the subtree under the child: its own child would reach depth 3.
        $this->put($this->row('s0', 'Subtree', 6, parent: 't1'));
        $report = $this->sync($board);
        $this->em->clear();
        self::assertNull($this->taskTitled('Subtree')->getParent());
        self::assertContains('parent_too_deep', $this->codes($report->warnings));

        // Even under the root its child would be at depth 2; a leaf fits, and the change goes through the port.
        $this->put($this->row('s0', 'Subtree', 7, parent: 't0'), $this->row('l0', 'Leaf', 8, parent: 't0'));
        $this->sync($board);
        $this->em->clear();
        self::assertNull($this->taskTitled('Subtree')->getParent());
        self::assertSame('Root', $this->taskTitled('Leaf')->getParent()?->getTitle());
        self::assertCount(1, $this->taskTitled('Leaf')->getChangeHistory()->filter(static fn ($row): bool => $row->getChangeType()->value === 'parent'));
    }

    /**
     * @param list<SyncError> $warnings
     *
     * @return list<string>
     */
    private function codes(array $warnings): array
    {
        return array_map(static fn (SyncError $warning): string => $warning->code, $warnings);
    }
}
