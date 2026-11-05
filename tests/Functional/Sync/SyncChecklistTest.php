<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Tests\Functional\Sync;

use Nowo\TaskBoardBundle\Entity\Task;
use Nowo\TaskBoardBundle\Entity\TaskBoard;
use Nowo\TaskBoardBundle\Sync\Dto\SyncStatus;
use Nowo\TaskBoardBundle\Sync\Entity\TaskSyncItem;
use Nowo\TaskBoardBundle\Sync\Provider\ContainerType;
use Nowo\TaskBoardBundle\Sync\Provider\ProviderCredential;
use Nowo\TaskBoardBundle\Sync\Provider\RemoteChecklist;
use Nowo\TaskBoardBundle\Sync\Provider\RemoteChecklistItem;
use Nowo\TaskBoardBundle\Sync\Provider\RemoteContainer;
use Nowo\TaskBoardBundle\Sync\Provider\RemoteStatusType;
use Nowo\TaskBoardBundle\Tests\Functional\App\TestCredentialResolver;

use function json_encode;

/**
 * Since 1.25 (§5.5, FR-025): ClickUp checklist items as subtasks — create, update, resolve (to the done column of the
 * status map, else the last one), archive when gone, restore when back, never recreated once deleted here, and the
 * deletions phase never touches them.
 */
final class SyncChecklistTest extends SyncPeopleFunctionalTestCase
{
    public function testChecklistItemsBecomeSubtasksAndFollowTheProvider(): void
    {
        $this->viewer('carol@example.test');
        $this->workspace();
        $board = $this->boardWithColumns('To do', 'Doing', 'Done');
        $doing = $board->getColumns()->get(1);
        self::assertNotNull($doing);
        $this->link($board, [['status' => 'complete', 'column' => $doing->getId(), 'type' => RemoteStatusType::Closed->value]]);
        $this->put($this->row('t1', 'Parent', 1, checklists: [
            new RemoteChecklist('cl1', 'Steps', 0, [
                new RemoteChecklistItem('i1', 'Write', 0, true, self::ref('9001', 'carol@example.test')),
                new RemoteChecklistItem('i2', 'Nested', 1, false, null, 'i1'),
                new RemoteChecklistItem('i3', '   ', 2),
            ]),
        ]));

        $report = $this->sync($board);
        self::assertSame(SyncStatus::Succeeded, $report->status, (string) json_encode($report->toArray()));
        self::assertSame(3, $report->stat('checklist.created'));
        $this->em->clear();
        $write = $this->taskTitled('Write');
        self::assertSame('Doing', $write->getColumn()?->getName(), 'a resolved item goes to the column of the first closed status');
        self::assertTrue($write->isCompleted());
        self::assertSame(['checklist:Steps'], $write->getTags());
        self::assertSame('Parent', $write->getParent()?->getTitle());
        self::assertCount(1, $write->getMembers());
        self::assertSame('To do', $this->taskTitled('Nested')->getColumn()?->getName());
        self::assertSame('—', $this->taskTitled('—')->getTitle());
        self::assertSame(TaskSyncItem::KIND_CHECKLIST_ITEM, $this->itemOf('t1#i1')->getItemKind());

        // The deletions scan never archives "<task>#<item>" rows (the provider does not list them).
        $this->sync($board);
        $this->em->clear();
        self::assertFalse($this->taskTitled('Write')->isArchived());

        // Renamed, unresolved, one gone, one deleted here.
        $nested = $this->taskTitled('Nested');
        $this->em->remove($this->taskTitled('—'));
        $this->em->flush();
        $write = $this->taskTitled('Write');
        $write->setTags([]);
        $this->em->flush();
        $this->put($this->row('t1', 'Parent', 2, checklists: [
            new RemoteChecklist('cl1', 'Steps', 0, [
                new RemoteChecklistItem('i1', 'Write it', 0, false),
                new RemoteChecklistItem('i3', 'Third', 2),
            ]),
        ]));
        $report = $this->sync($board);
        self::assertSame(1, $report->stat('checklist.updated'));
        self::assertSame(1, $report->stat('checklist.archived'));
        $this->em->clear();
        $write = $this->taskTitled('Write it');
        self::assertFalse($write->isCompleted());
        self::assertSame('To do', $write->getColumn()?->getName());
        self::assertSame(['checklist:Steps'], $write->getTags());
        self::assertTrue($this->em->find(Task::class, $nested->getId())?->isArchived());
        self::assertNull($this->em->getRepository(Task::class)->findOneBy(['title' => 'Third']), 'deleted here: never recreated');

        // The item comes back: restored; resolved again.
        $this->put($this->row('t1', 'Parent', 3, checklists: [
            new RemoteChecklist('cl1', 'Steps', 0, [
                new RemoteChecklistItem('i1', 'Write it', 0, true),
                new RemoteChecklistItem('i2', 'Nested', 1, false, null, 'i1'),
            ]),
        ]));
        $this->sync($board);
        $this->em->clear();
        self::assertFalse($this->taskTitled('Nested')->isArchived());
        self::assertTrue($this->taskTitled('Write it')->isCompleted());
        self::assertSame('Doing', $this->taskTitled('Write it')->getColumn()?->getName());
    }

    /** §5.5: a parent archived in the provider archives its checklist subtasks; restored with it. */
    public function testAParentArchivedRemotelyArchivesItsChecklistSubtasks(): void
    {
        $board = $this->boardWithColumns('To do', 'Done');
        $steps = [new RemoteChecklist('cl1', 'Steps', 0, [new RemoteChecklistItem('i1', 'Step one', 0), new RemoteChecklistItem('i2', 'Step two', 1)])];
        $this->put($this->row('t1', 'Parent', 1, checklists: $steps));
        $this->sync($board);
        $this->em->clear();
        $one = $this->taskTitled('Step one');
        $one->archive();
        $this->em->flush();

        $this->put($this->row('t1', 'Parent', 2, checklists: $steps, archived: true));
        $report = $this->sync($board);
        self::assertSame(1, $report->stat('checklist.archived'), 'only the subtask not archived yet');
        $this->em->clear();
        self::assertTrue($this->taskTitled('Parent')->isArchived());
        self::assertSame(Task::ARCHIVE_REASON_REMOTE, $this->taskTitled('Step two')->getArchiveReason());
        self::assertSame(Task::ARCHIVE_REASON_USER, $this->taskTitled('Step one')->getArchiveReason());

        $this->put($this->row('t1', 'Parent', 3, checklists: $steps));
        $this->sync($board);
        $this->em->clear();
        self::assertFalse($this->taskTitled('Parent')->isArchived());
        self::assertFalse($this->taskTitled('Step two')->isArchived(), 'restored with its parent');
    }

    public function testWithoutAStatusMapResolvedItemsGoToTheLastColumnAndChecklistsCanBeOff(): void
    {
        $board = $this->boardWithColumns('To do', 'Doing', 'Done');
        $this->put($this->row('t1', 'Parent', 1, checklists: [new RemoteChecklist('cl1', 'Steps', 0, [new RemoteChecklistItem('i1', 'Done item', 0, true)])]));
        $this->sync($board);
        $this->em->clear();
        self::assertSame('Done', $this->taskTitled('Done item')->getColumn()?->getName());
    }

    /**
     * @param list<array{status: string, column: string, type?: string}> $map
     */
    private function link(TaskBoard $board, array $map): void
    {
        $this->service()->link($board, $this->provider, new ProviderCredential(TestCredentialResolver::$secret, 'conn-1'), new RemoteContainer(self::LIST, 'Backlog', ContainerType::List, 's:1', true, false), $this->owner, [], $map);
    }
}
