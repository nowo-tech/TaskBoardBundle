<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Tests\Functional\Sync;

use DateTimeImmutable;
use Nowo\TaskBoardBundle\Entity\TaskDocument;
use Nowo\TaskBoardBundle\Enum\TaskDependencyType;
use Nowo\TaskBoardBundle\Enum\TaskPriority;
use Nowo\TaskBoardBundle\Event\TaskAccessCheckEvent;
use Nowo\TaskBoardBundle\Event\TaskBoardEvents;
use Nowo\TaskBoardBundle\Import\Dto\ImportedTaskDto;
use Nowo\TaskBoardBundle\Sync\Dto\SyncConflict;
use Nowo\TaskBoardBundle\Sync\Dto\SyncError;
use Nowo\TaskBoardBundle\Sync\Dto\SyncStatus;
use Nowo\TaskBoardBundle\Sync\Provider\RemoteTask;
use Nowo\TaskBoardBundle\Sync\SyncTaskWriter;
use Nowo\TaskBoardBundle\Tests\Functional\App\TestSyncLimitChecker;
use ReflectionMethod;
use RuntimeException;
use stdClass;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

use function array_map;
use function array_values;

/**
 * Since 1.23: the writer's per-field rule on every field, relations, remote archive states, the deletions scan and a
 * failure inside the page transaction.
 */
final class SyncWriterRulesTest extends SyncFunctionalTestCase
{
    public function testEveryFieldIsBaselinedFollowedAndNotedOnConflict(): void
    {
        $board = $this->board();
        $this->put($this->full('p1', 'Parent', 1), $this->full('p2', 'Other parent', 2));
        $this->put($this->full('c1', 'Child', 3, status: 'To do', priority: TaskPriority::High, start: '2030-01-01', due: '2030-01-05', estimate: 30, tags: ['b', 'a'], emails: ['owner@example.com', 'owner@example.com', 'ada@example.com']));

        $first = $this->sync($board);
        self::assertSame(3, $first->created);
        self::assertContains('assignee_unresolved', $this->codes($first->warnings), 'ada is not assignable');
        $this->em->clear();
        $this->refreshOwner();
        $child = $this->taskTitled('Child');
        self::assertSame('2030-01-01', $child->getStartAt()?->format('Y-m-d'));
        self::assertCount(1, $child->getMembers());

        // The provider changes every field: all of them follow (assignees cleared, a parent set).
        $this->put($this->full('c1', 'Child', 4, status: 'Doing', priority: TaskPriority::Low, start: '2030-02-01', due: '2030-02-05', estimate: 60, tags: ['c'], parent: 'p1'));
        $second = $this->sync($board);
        self::assertSame(1, $second->updated);
        $this->em->clear();
        $this->refreshOwner();
        $child = $this->taskTitled('Child');
        self::assertSame(['Parent', 'Doing', TaskPriority::Low, 60, ['c']], [$child->getParent()?->getTitle(), $child->getColumn()?->getName(), $child->getPriority(), $child->getEstimatedMinutes(), $child->getTags()]);

        // Every field edited here and again there: one conflict per field, the local values kept.
        $child->setPriority(TaskPriority::Urgent)->setStartAt(new DateTimeImmutable('2031-01-01'))->setDueAt(new DateTimeImmutable('2031-01-05'))->setEstimatedMinutes(5)->setTags(['local'])->setParent(null);
        $child->setColumn($child->getBoard()->getColumns()->get(2));
        foreach ($child->getMembers()->toArray() as $member) {
            $child->removeMember($member);
        }
        $this->em->flush();
        $this->put($this->full('c1', 'Child', 5, status: 'To do', priority: TaskPriority::Normal, start: '2032-01-01', due: '2032-01-05', estimate: 90, tags: ['remote'], emails: ['owner@example.com'], parent: 'p2'));
        $third  = $this->sync($board);
        $fields = array_map(static fn (SyncConflict $c): string => $c->field, $third->conflicts);
        foreach (['priority', 'column', 'start', 'due', 'estimate', 'tags', 'parent'] as $field) {
            self::assertContains($field, $fields);
        }
        $previews = [];
        foreach ($third->conflicts as $conflict) {
            $previews[$conflict->field] = $conflict->remotePreview;
        }
        self::assertSame(['To do', 'p2', 'remote'], [$previews['column'], $previews['parent'], $previews['tags']]);
        $this->em->clear();
        $this->refreshOwner();
        self::assertSame('Done', $this->taskTitled('Child')->getColumn()?->getName());

        // The same parent again is no move.
        $this->em->clear();
        $this->refreshOwner();
        $child = $this->taskTitled('Child');
        $child->setParent(null);
        $this->em->flush();
        // A parent set here that the provider then names too: no move.
        $this->put($this->full('c2', 'Second child', 6));
        $this->sync($board);
        $this->em->clear();
        $this->refreshOwner();
        $this->taskTitled('Second child')->setParent($this->taskTitled('Parent'));
        $this->em->flush();
        $this->put($this->full('c2', 'Second child', 7, parent: 'p1'));
        $this->sync($board);
        $this->em->clear();
        self::assertSame('Parent', $this->taskTitled('Second child')->getParent()?->getTitle());
    }

    public function testAssigneesRemovedHereStayRemovedAndTheirConflictPreview(): void
    {
        $board = $this->board();
        $this->put($this->full('a1', 'Assigned', 1, emails: ['owner@example.com']));
        $this->sync($board);

        $this->em->clear();
        $this->refreshOwner();
        $task = $this->taskTitled('Assigned');
        foreach ($task->getMembers()->toArray() as $member) {
            $task->removeMember($member);
        }
        $this->em->flush();
        $this->put($this->full('a1', 'Assigned', 2, tags: ['x'], emails: ['owner@example.com']));
        self::assertSame([], $this->sync($board)->conflicts, 'the provider did not change the assignees: the removal stays');
        $this->em->clear();
        $this->refreshOwner();
        self::assertCount(0, $this->taskTitled('Assigned')->getMembers());

        // Assignees the provider adds later (none at first) follow.
        $this->put($this->full('a2', 'Later', 3));
        $this->sync($board);
        $this->put($this->full('a2', 'Later', 4, emails: ['owner@example.com']));
        $this->sync($board);
        $this->em->clear();
        $this->refreshOwner();
        self::assertCount(1, $this->taskTitled('Later')->getMembers());

        // Only the board's creator is assignable in this kernel: the assignee preview is checked directly.
        $writer  = self::getContainer()->get(SyncTaskWriter::class);
        $preview = new ReflectionMethod(SyncTaskWriter::class, 'preview');
        $row     = new ImportedTaskDto(title: 't', assigneeEmail: 'ada@example.com', assigneeNames: ['Grace'], assigneeEmails: ['ada@example.com']);
        self::assertSame('ada@example.com, Grace', $preview->invoke($writer, 'assignees', ['x'], null, $row));
        $userIds = new ReflectionMethod(SyncTaskWriter::class, 'userIds');
        self::assertCount(1, (array) $userIds->invoke($writer, [new stdClass()]));
    }

    public function testRelationsCyclesAndRemoteArchiveStates(): void
    {
        $board = $this->board();
        // A parent cycle inside one page, a dependency on a task that never arrives, a dependency cycle.
        $this->put(
            $this->full('x1', 'Cycle one', 1, parent: 'x2'),
            $this->full('x2', 'Cycle two', 2, parent: 'x1'),
            $this->full('g1', 'Ghost', 3, depends: [['nowhere', TaskDependencyType::BlockedBy]]),
            $this->full('d1', 'Dep one', 4, depends: [['d2', TaskDependencyType::Blocks]]),
            $this->full('d2', 'Dep two', 5, depends: [['d1', TaskDependencyType::Blocks]]),
            $this->full('z1', 'Gone before', 6, archived: true),
        );
        $report = $this->sync($board);
        self::assertSame(5, $report->created, 'a row archived there and unknown here is not created');
        self::assertSame(1, $report->unchanged);
        $codes = $this->codes($report->warnings);
        self::assertContains('relation_skipped', $codes);
        self::assertContains('parent_cycle', $codes);

        // A page (later in the run) names a task of an earlier run as its parent.
        $this->put($this->full('k1', 'Kid', 200_000, parent: 'g1'));
        $this->sync($board);
        $this->em->clear();
        $this->refreshOwner();
        self::assertSame('Ghost', $this->taskTitled('Kid')->getParent()?->getTitle());

        // Archived there, then a full run sees it archived again: unchanged.
        $this->put($this->full('k1', 'Kid', 200_001, parent: 'g1', archived: true));
        self::assertSame(1, $this->sync($board)->archived);
        self::assertGreaterThanOrEqual(1, $this->sync($board, full: true)->unchanged);

        // A status without a column (creation off) and an empty status (the fallback column).
        $board    = $this->freshBoard($board);
        $fallback = $board->getColumns()->get(1)?->getId();
        $this->put($this->full('s1', 'No column', 200_002, status: 'Nowhere'), $this->full('s2', 'Blank status', 200_003, status: ''));
        $report = $this->sync($board, createMissingColumns: false, fallbackColumnId: $fallback);
        self::assertContains('status_without_column', $this->codes($report->warnings));
        $this->em->clear();
        $this->refreshOwner();
        self::assertSame('Doing', $this->taskTitled('No column')->getColumn()?->getName());
    }

    public function testTheDeletionsScanRestoresWithinTheQuotaAndSkipsReadOnlyTasks(): void
    {
        $board = $this->board();
        $this->put($this->full('o1', 'Old', 1), $this->full('o2', 'Recent', 100_000), $this->full('o3', 'Locked', 2));
        $this->sync($board);

        $this->provider->removeTask(self::LIST, 'o1');
        self::assertSame(1, $this->sync($board, allowMassArchive: true)->archived);
        // Back with its old date: only the deletions scan sees it.
        $this->put($this->full('o1', 'Old', 1));
        TestSyncLimitChecker::$refuseTasks = true;
        self::assertContains('restore_refused', $this->codes($this->sync($board)->warnings));
        TestSyncLimitChecker::$refuseTasks = false;
        self::assertSame(1, $this->sync($board)->restored);

        // A task the actor may not edit is never archived by the scan.
        $locked     = $this->taskTitled('Locked')->getId();
        $dispatcher = self::getContainer()->get('event_dispatcher');
        self::assertInstanceOf(EventDispatcherInterface::class, $dispatcher);
        $dispatcher->addListener(TaskBoardEvents::TASK_ACCESS_CHECK, static function (TaskAccessCheckEvent $event) use ($locked): void {
            if ($event->getTask()->getId() === $locked) {
                $event->grant();
                $event->markReadOnly();
            }
        });
        $this->provider->removeTask(self::LIST, 'o3');
        self::assertSame(0, $this->sync($board, allowMassArchive: true)->archived);
    }

    public function testAFieldsDocumentFollowsAndADeferredTaskKeepsItsComments(): void
    {
        $board = $this->board();
        $this->put($this->full('f1', 'Fields', 1, fields: 'Size: S'));
        $this->sync($board);
        $this->put($this->full('f1', 'Fields', 2, fields: 'Size: L'));
        $this->sync($board);
        $this->em->clear();
        $this->refreshOwner();
        $document = $this->em->getRepository(TaskDocument::class)->findOneBy(['task' => $this->taskTitled('Fields')->getId()]);
        self::assertInstanceOf(TaskDocument::class, $document);
        self::assertSame('Size: L', $document->getContent());

        // Comments fail with a provider error: the task waits for the next run and the run fails.
        $this->put($this->full('f1', 'Fields', 3, fields: 'Size: XL'));
        $report = $this->sync($board, new RepeatingProvider($this->provider, repeat: false, failComments: true));
        self::assertSame(SyncStatus::Failed, $report->status);
        self::assertContains('task_deferred', $this->codes($report->warnings));
    }

    public function testRepeatedRowsAndAFailureInsideThePageTransaction(): void
    {
        $board    = $this->board();
        $repeater = new RepeatingProvider($this->provider, repeat: true);
        $this->put($this->full('r1', 'Parent', 1), $this->full('r2', 'Child', 200_000));
        $report = $this->sync($board, $repeater);
        self::assertSame(2, $report->created);
        self::assertSame(2, $report->skips->repeated);

        // The child now names the parent; checking the parent inside the transaction fails unexpectedly.
        $parent     = $this->taskTitled('Parent')->getId();
        $dispatcher = self::getContainer()->get('event_dispatcher');
        self::assertInstanceOf(EventDispatcherInterface::class, $dispatcher);
        $dispatcher->addListener(TaskBoardEvents::TASK_ACCESS_CHECK, static function (TaskAccessCheckEvent $event) use ($parent): void {
            if ($event->getTask()->getId() === $parent) {
                throw new RuntimeException('boom');
            }
        });
        $this->put($this->full('r2', 'Child renamed', 200_001, parent: 'r1'));
        $report = $this->sync($board);
        self::assertSame(SyncStatus::Failed, $report->status);
        $this->em->clear();
        $this->refreshOwner();
        self::assertSame('Child', $this->taskTitled('Child')->getTitle(), 'the page was rolled back');
    }

    /**
     * @param list<string> $tags
     * @param list<string> $emails
     * @param list<array{0: string, 1: TaskDependencyType}> $depends
     */
    private function full(
        string $id,
        string $title,
        int $updated,
        ?string $status = 'To do',
        TaskPriority $priority = TaskPriority::Normal,
        ?string $start = null,
        ?string $due = null,
        ?int $estimate = null,
        array $tags = [],
        array $emails = [],
        ?string $parent = null,
        array $depends = [],
        bool $archived = false,
        ?string $fields = null,
    ): RemoteTask {
        return new RemoteTask(
            task: new ImportedTaskDto(
                title: $title,
                externalId: $id,
                parentExternalId: $parent,
                status: $status,
                priority: $priority,
                assigneeEmail: $emails[0] ?? null,
                dueAt: $due !== null ? new DateTimeImmutable($due) : null,
                estimatedMinutes: $estimate,
                tags: $tags,
                startAt: $start !== null ? new DateTimeImmutable($start) : null,
                dependsOn: $depends,
                hasPriority: true,
                assigneeEmails: $emails,
            ),
            remoteUpdatedAt: new DateTimeImmutable('@' . (1_790_000_000 + $updated)),
            archived: $archived,
            customFieldsText: $fields,
        );
    }

    /**
     * @param list<SyncError> $warnings
     *
     * @return list<string>
     */
    private function codes(array $warnings): array
    {
        return array_values(array_map(static fn (SyncError $w): string => $w->code, $warnings));
    }
}
