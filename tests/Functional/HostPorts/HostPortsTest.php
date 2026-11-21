<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Tests\Functional\HostPorts;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use Nowo\TaskBoardBundle\Dto\BoardColumnFormData;
use Nowo\TaskBoardBundle\Dto\BoardCriteria;
use Nowo\TaskBoardBundle\Dto\TaskCriteria;
use Nowo\TaskBoardBundle\Dto\TaskFormData;
use Nowo\TaskBoardBundle\Dto\TaskLinkFormData;
use Nowo\TaskBoardBundle\Dto\TaskMemberFormData;
use Nowo\TaskBoardBundle\Entity\Task;
use Nowo\TaskBoardBundle\Entity\TaskBoard;
use Nowo\TaskBoardBundle\Entity\TaskTimeEntry;
use Nowo\TaskBoardBundle\Enum\TaskDependencyType;
use Nowo\TaskBoardBundle\Enum\TaskMemberRole;
use Nowo\TaskBoardBundle\Enum\TaskPriority;
use Nowo\TaskBoardBundle\Enum\TaskSort;
use Nowo\TaskBoardBundle\Event\TaskBoardEvents;
use Nowo\TaskBoardBundle\Exception\TaskBoardAccessDeniedException;
use Nowo\TaskBoardBundle\Exception\TaskChangedException;
use Nowo\TaskBoardBundle\Exception\UnassignableUserException;
use Nowo\TaskBoardBundle\Service\TaskBoardAccessInterface;
use Nowo\TaskBoardBundle\Service\TaskBoardQueryInterface;
use Nowo\TaskBoardBundle\Service\TaskCommandsInterface;
use Nowo\TaskBoardBundle\Service\TaskFlowStatsInterface;
use Nowo\TaskBoardBundle\Service\TaskMemberAssigner;
use Nowo\TaskBoardBundle\Tests\Functional\App\Entity\AppUser;
use Nowo\TaskBoardBundle\Tests\Functional\FunctionalTestCase;
use Nowo\TaskBoardBundle\ValueObject\UserRef;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

use function array_map;
use function count;

/**
 * Since 2.29.0 (DDD-host checklist B1 / B7 / B8): the id-based host ports on the functional kernel: a host passes ids and
 * a {@see UserRef}, gets views and ids back, and every user action re-checks the page's access rule.
 */
final class HostPortsTest extends FunctionalTestCase
{
    private TaskCommandsInterface $commands;

    private TaskBoardAccessInterface $access;

    private TaskBoardQueryInterface $query;

    private UserRef $ownerRef;

    protected function setUp(): void
    {
        self::$taskBoardConfig = ['security' => ['manage_roles' => ['ROLE_ADMIN']]];
        parent::setUp();
        $container      = self::getContainer();
        $this->commands = $container->get(TaskCommandsInterface::class);
        $this->access   = $container->get(TaskBoardAccessInterface::class);
        $this->query    = $container->get(TaskBoardQueryInterface::class);
        $this->ownerRef = $this->ref($this->owner);
    }

    public function testCreateUpdateMoveCompleteArchiveRestoreDelete(): void
    {
        $board = $this->board('Roadmap', ['To do', 'Doing', 'Done'], tenantKey: 'acme');
        $data  = new TaskFormData('Write the spec', 'Body', TaskPriority::High, $board->getColumns()->get(0)?->getId(), 30, new DateTimeImmutable('2026-12-01'), ['spec', 'q4'], '#112233', new DateTimeImmutable('2026-11-25'));

        $view = $this->commands->createTask($this->ownerRef, $board->getId(), $data);
        self::assertSame(['Write the spec', 'Body', TaskPriority::High, 30, ['spec', 'q4'], '#112233', 'acme', (string) $this->owner->getId()], [$view->title, $view->description, $view->priority, $view->estimatedMinutes, $view->tags, $view->color, $view->tenantKey, $view->creatorId]);
        self::assertFalse($view->isCompleted());
        self::assertFalse($view->isArchived());

        $sub = $this->commands->createTask($this->ownerRef, $board->getId(), new TaskFormData('Child'), $view->id);
        self::assertSame($view->id, $sub->parentId);

        $updated = $this->commands->updateTask($this->ownerRef, $view->id, new TaskFormData('Write the spec v2', columnId: $view->columnId), $view->revision);
        self::assertSame('Write the spec v2', $updated->title);
        self::assertGreaterThan($view->revision, $updated->revision);

        $moved = $this->commands->moveTask($this->ownerRef, $view->id, $board->getColumns()->get(1)?->getId(), 0);
        self::assertSame($board->getColumns()->get(1)?->getId(), $moved->columnId);

        $done = $this->commands->completeTask($this->ownerRef, $view->id);
        self::assertTrue($done->isCompleted());
        self::assertSame($board->getColumns()->get(2)?->getId(), $done->columnId);
        self::assertTrue($this->commands->completeTask($this->ownerRef, $view->id)->isCompleted(), 'again: nothing changes');

        self::assertTrue($this->commands->archiveTask($this->ownerRef, $view->id)->isArchived());
        self::assertFalse($this->commands->restoreTask($this->ownerRef, $view->id)->isArchived());

        self::assertSame(2, $this->commands->deleteTask($this->ownerRef, $view->id), 'with its subtask');
        self::assertNull($this->query->findTaskById($view->id));
    }

    public function testCreateCompletedAndCompleteOnABoardWithoutColumns(): void
    {
        $board = $this->board('Inbox', []);
        $view  = $this->commands->createTask($this->ownerRef, $board->getId(), new TaskFormData('Already done'), completed: true);
        self::assertTrue($view->isCompleted());
        self::assertNull($view->columnId);

        $open = $this->commands->createTask($this->ownerRef, $board->getId(), new TaskFormData('Open'));
        self::assertFalse($open->isCompleted());
        $completed = [];
        self::getContainer()->get(EventDispatcherInterface::class)->addListener(TaskBoardEvents::TASK_COMPLETED, static function () use (&$completed): void {
            $completed[] = true;
        });
        self::assertTrue($this->commands->completeTask($this->ownerRef, $open->id)->isCompleted());
        self::assertCount(1, $completed, 'TASK_COMPLETED once, after the commit');

        $withColumns = $this->board('Columns');
        self::assertSame($withColumns->getColumns()->get(2)?->getId(), $this->commands->createTask($this->ownerRef, $withColumns->getId(), new TaskFormData('Done at once'), completed: true)->columnId);
    }

    public function testAnExpectedRevisionThatChangedIsAConflictAndWritesNothing(): void
    {
        $board = $this->board();
        $view  = $this->commands->createTask($this->ownerRef, $board->getId(), new TaskFormData('Raced'));
        $this->commands->updateTask($this->ownerRef, $view->id, new TaskFormData('First'));

        try {
            $this->commands->updateTask($this->ownerRef, $view->id, new TaskFormData('Lost update'), $view->revision);
            self::fail('A stale revision must be refused.');
        } catch (TaskChangedException $changed) {
            self::assertSame($view->revision + 1, $changed->currentRevision);
        }
        try {
            $this->commands->moveTask($this->ownerRef, $view->id, null, 0, $view->revision);
            self::fail('A stale revision must be refused.');
        } catch (TaskChangedException) {
        }
        self::assertSame('First', $this->query->findTaskById($view->id)?->title);
    }

    public function testUnknownIdsUnknownUsersAndRefusalsLookTheSame(): void
    {
        $board    = $this->board();
        $task     = $this->task($board, 'Private');
        $stranger = $this->ref($this->user('stranger@example.com'));
        $nobody   = UserRef::ofId('999999');

        $calls = [
            fn () => $this->commands->createTask($stranger, $board->getId(), new TaskFormData('x')),
            fn () => $this->commands->createTask($this->ownerRef, 'missing', new TaskFormData('x')),
            fn () => $this->commands->createTask($nobody, $board->getId(), new TaskFormData('x')),
            fn () => $this->commands->updateTask($stranger, $task->getId(), new TaskFormData('x')),
            fn () => $this->commands->updateTask($this->ownerRef, 'missing', new TaskFormData('x')),
            fn () => $this->commands->updateTask($this->ownerRef, strtoupper($task->getId()), new TaskFormData('x')),
            fn () => $this->commands->moveTask($stranger, $task->getId(), null, 0),
            fn () => $this->commands->completeTask($stranger, $task->getId()),
            fn () => $this->commands->archiveTask($stranger, $task->getId()),
            fn () => $this->commands->restoreTask($stranger, $task->getId()),
            fn () => $this->commands->deleteTask($stranger, $task->getId()),
            fn () => $this->commands->deleteTask($this->ownerRef, 'missing'),
            fn () => $this->commands->addMember($stranger, $task->getId(), $this->ownerRef),
            fn () => $this->commands->addMember($this->ownerRef, $task->getId(), $nobody),
            fn () => $this->commands->removeMember($stranger, $task->getId(), 'm'),
            fn () => $this->commands->addComment($stranger, $task->getId(), 'hi'),
            fn () => $this->commands->addComment($this->ownerRef, 'missing', 'hi'),
            fn () => $this->commands->addLink($stranger, $task->getId(), new TaskLinkFormData(url: 'https://example.com')),
            fn () => $this->commands->addHostObjectLink($stranger, $task->getId(), 'invoice', '1'),
            fn () => $this->commands->addDependency($stranger, $task->getId(), $task->getId(), TaskDependencyType::Blocks),
            fn () => $this->commands->addDocument($stranger, $task->getId(), 'Doc', 'Body'),
            fn () => $this->commands->logTime($stranger, $task->getId(), 30, new DateTimeImmutable()),
            fn () => $this->commands->logTime($this->ownerRef, 'missing', 30, new DateTimeImmutable()),
            fn () => $this->commands->addColumn($stranger, $board->getId(), new BoardColumnFormData('New')),
            fn () => $this->commands->addColumn($this->ownerRef, 'missing', new BoardColumnFormData('New')),
            fn () => $this->commands->reorderColumns($stranger, $board->getId(), []),
        ];
        foreach ($calls as $index => $call) {
            try {
                $call();
                self::fail('Call ' . $index . ' must be refused.');
            } catch (TaskBoardAccessDeniedException $denied) {
                self::assertSame('TaskBoard: not found or not allowed.', $denied->getMessage(), 'call ' . $index);
            }
        }
    }

    public function testMembersCommentsLinksDependenciesDocumentsAndTime(): void
    {
        $board     = $this->board();
        $task      = $this->commands->createTask($this->ownerRef, $board->getId(), new TaskFormData('Work'));
        $other     = $this->commands->createTask($this->ownerRef, $board->getId(), new TaskFormData('Other'));
        $colleague = $this->user('colleague@example.com');

        $member = $this->commands->addMember($this->ownerRef, $task->id, $this->ref($colleague), TaskMemberRole::Assignee);
        self::assertSame($member, $this->commands->addMember($this->ownerRef, $task->id, $this->ref($colleague), TaskMemberRole::Assignee), 'same member again');
        self::assertTrue($this->commands->removeMember($this->ownerRef, $task->id, $member));
        self::assertFalse($this->commands->removeMember($this->ownerRef, $task->id, $member));

        $comment = $this->commands->addComment($this->ownerRef, $task->id, 'Looks good', [(string) $colleague->getId()]);
        self::assertNotSame('', $comment);
        $link = $this->commands->addLink($this->ownerRef, $task->id, new TaskLinkFormData(url: 'https://example.com/spec', label: 'Spec'));
        self::assertNotSame('', $link);
        $object = $this->commands->addHostObjectLink($this->ownerRef, $task->id, 'invoice', 'INV-1', 'Invoice 1');
        self::assertSame($object, $this->commands->addHostObjectLink($this->ownerRef, $task->id, 'invoice', 'INV-1'), 'linked once');
        $dependency = $this->commands->addDependency($this->ownerRef, $task->id, $other->id, TaskDependencyType::Blocks);
        self::assertNotSame('', $dependency);
        $first  = $this->commands->addDocument($this->ownerRef, $task->id, 'Notes', '# Notes');
        $second = $this->commands->addDocument($this->ownerRef, $task->id, 'More', 'Text', 5);
        $entry  = $this->commands->logTime($this->ownerRef, $task->id, 45, new DateTimeImmutable('2026-11-20'), 'Review');

        $this->em->clear();
        $loaded = $this->em->find(Task::class, $task->id);
        self::assertInstanceOf(Task::class, $loaded);
        self::assertCount(2, $loaded->getLinks());
        $positions = [];
        foreach ($loaded->getDocuments() as $document) {
            $positions[$document->getId()] = $document->getPosition();
        }
        self::assertSame([$first => 0, $second => 5], $positions);
        self::assertSame(45, $this->em->find(TaskTimeEntry::class, $entry)?->getMinutes());
    }

    public function testInvalidInputIsRefusedBeforeAnyWrite(): void
    {
        $board = $this->board();
        $task  = $this->commands->createTask($this->ownerRef, $board->getId(), new TaskFormData('Work'));
        $other = $this->board('Other');
        $calls = [
            fn () => $this->commands->createTask($this->ownerRef, $board->getId(), new TaskFormData('  ')),
            fn () => $this->commands->createTask($this->ownerRef, $other->getId(), new TaskFormData('Sub'), $task->id),
            fn () => $this->commands->updateTask($this->ownerRef, $task->id, new TaskFormData(str_repeat('x', 256))),
            fn () => $this->commands->addComment($this->ownerRef, $task->id, ' '),
            fn () => $this->commands->addLink($this->ownerRef, $task->id, new TaskLinkFormData(url: '')),
            fn () => $this->commands->addHostObjectLink($this->ownerRef, $task->id, '', '1'),
            fn () => $this->commands->addDocument($this->ownerRef, $task->id, '', 'Body'),
            fn () => $this->commands->logTime($this->ownerRef, $task->id, 0, new DateTimeImmutable()),
            fn () => $this->commands->addColumn($this->ownerRef, $board->getId(), new BoardColumnFormData('')),
            fn () => $this->commands->reorderColumns($this->ownerRef, $board->getId(), ['a']),
            fn () => $this->commands->clearTimeEntrySnapshotsForUser(' u'),
        ];
        foreach ($calls as $index => $call) {
            try {
                $call();
                self::fail('Call ' . $index . ' must be refused.');
            } catch (InvalidArgumentException) {
            }
        }
        self::assertSame(1, $this->query->countTasks(TaskCriteria::forBoards([$board->getId()])));
    }

    public function testColumnsAreAddedAtAPositionAndReordered(): void
    {
        $board  = $this->board('Flow', ['A', 'B']);
        $middle = $this->commands->addColumn($this->ownerRef, $board->getId(), new BoardColumnFormData('Middle'), 1);
        $last   = $this->commands->addColumn($this->ownerRef, $board->getId(), new BoardColumnFormData('Last'));
        self::assertSame(['A', 'Middle', 'B', 'Last'], $this->columnNames($board->getId()));

        $ids = $this->columnIds($board->getId());
        $this->commands->reorderColumns($this->ownerRef, $board->getId(), [$last, $ids[0], $middle, $ids[2]]);
        self::assertSame(['Last', 'A', 'Middle', 'B'], $this->columnNames($board->getId()));
    }

    public function testAdjustTotalTimeIsClampedAndSnapshotsAreClearedForOneUser(): void
    {
        $board = $this->board();
        $task  = $this->commands->createTask($this->ownerRef, $board->getId(), new TaskFormData('Timed'));
        self::assertSame(120, $this->commands->adjustTotalTime($task->id, 120));
        self::assertSame(20, $this->commands->adjustTotalTime($task->id, -100));
        self::assertSame(0, $this->commands->adjustTotalTime($task->id, -500));
        self::assertNull($this->commands->adjustTotalTime('missing', 5));
        self::assertSame(0, $this->query->findTaskById($task->id)?->totalTimeSeconds);

        $colleague = $this->user('colleague@example.com');
        $mine      = $this->commands->logTime($this->ownerRef, $task->id, 30, new DateTimeImmutable('2026-11-01'));
        $this->entry($task->id, $colleague);
        $this->commands->deleteTask($this->ownerRef, $task->id);

        self::assertSame(1, $this->commands->clearTimeEntrySnapshotsForUser((string) $this->owner->getId()));
        self::assertSame(0, $this->commands->clearTimeEntrySnapshotsForUser((string) $this->owner->getId()), 'idempotent');
        $this->em->clear();
        self::assertNull($this->em->find(TaskTimeEntry::class, $mine)?->getTaskTitleSnapshot());
        $kept = $this->em->getRepository(TaskTimeEntry::class)->findBy(['user' => $colleague->getId()]);
        self::assertSame(['Timed'], array_map(static fn (TaskTimeEntry $entry): ?string => $entry->getTaskTitleSnapshot(), $kept));
    }

    public function testTheMemberFormTakesAUserId(): void
    {
        $board     = $this->board();
        $task      = $this->task($board, 'Work');
        $colleague = $this->user('colleague@example.com');
        $assigner  = self::getContainer()->get(TaskMemberAssigner::class);

        $member = $assigner->assign($task, new TaskMemberFormData(userId: (string) $colleague->getId()), $this->owner);
        self::assertSame($colleague->getId(), $member->getUser()?->getId());

        foreach (['999999', ' bad'] as $id) {
            try {
                $assigner->assign($task, new TaskMemberFormData(userId: $id), $this->owner);
                self::fail('An id that names nobody is refused.');
            } catch (UnassignableUserException) {
            }
        }
    }

    public function testAccessPortByIdJudgesTheGivenUser(): void
    {
        $board    = $this->board();
        $task     = $this->task($board, 'Work');
        $admin    = $this->ref($this->user('admin@example.com', ['ROLE_USER', 'ROLE_ADMIN']));
        $stranger = $this->ref($this->user('stranger@example.com'));
        $noRoles  = $this->ref($this->user('noroles@example.com', []));
        // The signed-in user is the stranger: the administrator's bypass is still the administrator's own.
        $this->login($this->userByRef($stranger));

        self::assertTrue($this->access->canAccess($this->ownerRef));
        self::assertFalse($this->access->canAccess($noRoles));
        self::assertFalse($this->access->canAccess(UserRef::ofId('999999')));
        foreach ([[$this->ownerRef, true], [$admin, true], [$stranger, false], [$noRoles, false]] as [$user, $expected]) {
            self::assertSame($expected, $this->access->canViewBoard($user, $board->getId()));
            self::assertSame($expected, $this->access->canEditBoard($user, $board->getId()));
            self::assertSame($expected, $this->access->canManageBoard($user, $board->getId()));
            self::assertSame($expected, $this->access->canViewTask($user, $task->getId()));
            self::assertSame($expected, $this->access->canEditTask($user, $task->getId()));
        }
        self::assertTrue($this->access->canTrackTime($this->ownerRef, $task->getId()));
        self::assertFalse($this->access->canViewBoard($this->ownerRef, 'missing'));
        self::assertFalse($this->access->canViewTask($this->ownerRef, 'missing'));
        self::assertSame([$board->getId()], $this->access->filterBoardIds($this->ownerRef, ['missing', $board->getId()]));
        self::assertSame([], $this->access->filterBoardIds($stranger, [$board->getId()]));
        self::assertSame([], $this->access->filterBoardIds(UserRef::ofId('999999'), [$board->getId()]));

        $this->expectException(InvalidArgumentException::class);
        $this->access->filterBoardIds($this->ownerRef, array_fill(0, 501, 'x'));
    }

    public function testQueryPortListsAndCountsByExactTenantKey(): void
    {
        $acme   = $this->board('Acme', tenantKey: 'acme');
        $acme2  = $this->board('Acme two', tenantKey: 'acme');
        $globex = $this->board('Globex', tenantKey: 'globex');
        $closed = $this->board('Closed', tenantKey: 'acme');
        $closed->archive();
        $this->em->flush();

        $due  = $this->commands->createTask($this->ownerRef, $acme->getId(), new TaskFormData('Due soon', dueAt: new DateTimeImmutable('2026-12-02'), tags: ['ops', 'urgent']));
        $late = $this->commands->createTask($this->ownerRef, $acme2->getId(), new TaskFormData('Due later', dueAt: new DateTimeImmutable('2027-01-10'), tags: ['ops']));
        $this->commands->createTask($this->ownerRef, $acme->getId(), new TaskFormData('No date', tags: ['ops', 'ops']));
        $done = $this->commands->createTask($this->ownerRef, $acme->getId(), new TaskFormData('Done'), completed: true);
        $this->commands->createTask($this->ownerRef, $globex->getId(), new TaskFormData('Elsewhere', tags: ['other']));
        $child = $this->commands->createTask($this->ownerRef, $acme->getId(), new TaskFormData('Child'), $due->id);

        $page = $this->query->findBoards(BoardCriteria::forTenants(['acme', 'ACME', " acme"]));
        self::assertSame(['Acme', 'Acme two'], array_map(static fn ($b): string => $b->name, $page->items));
        self::assertSame(2, $page->total);
        self::assertFalse($page->hasMore());
        self::assertSame(['Closed'], array_map(static fn ($b): string => $b->name, $this->query->findBoards(BoardCriteria::forTenants(['acme'])->withArchived(true))->items));
        self::assertSame(3, $this->query->findBoards(BoardCriteria::forTenants(['acme'])->withArchived(null)->page(0, 1))->total);
        self::assertTrue($this->query->findBoards(BoardCriteria::forTenants(['acme'])->page(0, 1))->hasMore());
        self::assertSame(0, $this->query->findBoards(BoardCriteria::forTenants([' x']))->total);
        self::assertSame('Acme', $this->query->findBoardById($acme->getId())?->name);
        self::assertNull($this->query->findBoardById(''));
        self::assertNull($this->query->findBoardById(strtoupper($acme->getId())));
        self::assertSame(['acme' => 2, 'globex' => 1, 'none' => 0], $this->query->countActiveBoards(['acme', 'globex', 'none', ' bad']));
        self::assertSame([], $this->query->countActiveBoards([]));

        self::assertSame(['acme' => 4, 'globex' => 1], $this->query->countOpenTasks(['acme', 'globex']));
        self::assertSame([], $this->query->countOpenTasks([' ']));
        self::assertSame(['ops' => 3, 'urgent' => 1], $this->query->tagCounts(['acme']));
        self::assertSame([], $this->query->tagCounts([]));

        $window = TaskCriteria::forTenants(['acme'])->dueBetween(new DateTimeImmutable('2026-12-01'), new DateTimeImmutable('2026-12-31'))->withCompleted(false);
        self::assertSame([$due->id], array_map(static fn ($t): string => $t->id, $this->query->findTasks($window)->items));
        self::assertSame(1, $this->query->countTasks($window));

        $all = $this->query->findTasks(TaskCriteria::forTenants(['acme'])->sortedBy(TaskSort::DueAsc)->page(0, 10));
        self::assertSame(5, $all->total);
        self::assertSame([$due->id, $late->id], array_slice(array_map(static fn ($t): string => $t->id, $all->items), 0, 2));
        self::assertCount(4, $this->query->findTasks(TaskCriteria::forTenants(['acme'])->topLevelOnly())->items);
        self::assertSame(1, $this->query->countTasks(TaskCriteria::forTenants(['acme'])->withCompleted(true)));
        self::assertSame(5, $this->query->countTasks(TaskCriteria::forTenants(['acme'])->createdBy((string) $this->owner->getId())));
        self::assertSame(0, $this->query->countTasks(TaskCriteria::forTenants(['acme'])->createdBy('999999')));
        self::assertSame(0, $this->query->countTasks(TaskCriteria::forTenants(['acme'])->withArchived(true)));
        self::assertSame(5, $this->query->countTasks(TaskCriteria::forTenants(['acme'])->withArchived(null)));
        self::assertSame(1, $this->query->countTasks(TaskCriteria::forBoards([$acme2->getId()])->inColumn($late->columnId)));
        foreach ([TaskSort::CreatedAsc, TaskSort::CreatedDesc, TaskSort::UpdatedDesc] as $sort) {
            self::assertSame(5, count($this->query->findTasks(TaskCriteria::forTenants(['acme'])->sortedBy($sort))->items));
        }
        self::assertSame(0, $this->query->findTasks(TaskCriteria::forTenants([]))->total);
        self::assertSame(0, $this->query->countTasks(TaskCriteria::forBoards([''])));

        self::assertSame($child->id, $this->query->findTaskById($child->id)?->id);
        self::assertNull($this->query->findTaskById(''));
        self::assertSame([$done->id, $due->id], array_map(static fn ($t): string => $t->id, $this->query->findTasksByIds([$done->id, 'missing', '', $due->id])));
        self::assertSame([], $this->query->findTasksByIds(['']));

        $this->expectException(InvalidArgumentException::class);
        $this->query->findTasksByIds(array_fill(0, 501, 'x'));
    }

    public function testFlowStats(): void
    {
        $stats = self::getContainer()->get(TaskFlowStatsInterface::class);
        $board = $this->board('Flow', tenantKey: 'acme');
        $a     = $this->commands->createTask($this->ownerRef, $board->getId(), new TaskFormData('A'));
        $this->commands->createTask($this->ownerRef, $board->getId(), new TaskFormData('B'));
        $this->commands->moveTask($this->ownerRef, $a->id, $board->getColumns()->get(1)?->getId(), 0);
        $this->commands->completeTask($this->ownerRef, $a->id);

        $from = new DateTimeImmutable('-1 day');
        $to   = new DateTimeImmutable('+1 day');
        $flow = $stats->dailyFlow(['acme', 'other'], $from, $to, new DateTimeZone('Europe/Madrid'));
        self::assertCount(1, $flow);
        self::assertSame(['acme', 2, 1], [$flow[0]->tenantKey, $flow[0]->created, $flow[0]->completed]);
        self::assertSame([], $stats->dailyFlow([], $from, $to));

        $samples = $stats->completedSamples(['acme'], $from, $to);
        self::assertSame([$a->id], array_map(static fn ($s): string => $s->taskId, $samples));
        self::assertSame([], $stats->completedSamples([], $from, $to));

        $entries = $stats->firstEntries(['acme'], 'Doing', $from, $to);
        self::assertSame([$a->id], array_map(static fn ($e): string => $e->taskId, $entries));
        self::assertSame([], $stats->firstEntries(['acme'], '', $from, $to));
        self::assertSame([], $stats->firstEntries(['acme'], 'Doing', $to, $to->modify('+1 day')));

        foreach ([
            fn () => $stats->dailyFlow(['acme'], $to, $from),
            fn () => $stats->dailyFlow(['acme'], $from, $from->modify('+401 days')),
            fn () => $stats->completedSamples(['acme'], $from, $to, 0),
            fn () => $stats->firstEntries(array_fill(0, 501, 'k'), 'Doing', $from, $to),
        ] as $call) {
            try {
                $call();
                self::fail('Refused.');
            } catch (InvalidArgumentException) {
            }
        }
    }

    private function ref(AppUser $user): UserRef
    {
        return UserRef::ofId((string) $user->getId());
    }

    private function userByRef(UserRef $ref): AppUser
    {
        $user = $this->em->find(AppUser::class, (int) $ref->id);
        self::assertInstanceOf(AppUser::class, $user);

        return $user;
    }

    private function entry(string $taskId, AppUser $user): void
    {
        $task = $this->em->find(Task::class, $taskId);
        self::assertInstanceOf(Task::class, $task);
        $this->em->persist(new TaskTimeEntry($task, $user, 15, new DateTimeImmutable('2026-11-02')));
        $this->em->flush();
    }

    /** @return list<string> */
    private function columnNames(string $boardId): array
    {
        $this->em->clear();
        $board = $this->em->find(TaskBoard::class, $boardId);
        self::assertInstanceOf(TaskBoard::class, $board);
        $names = [];
        foreach ($board->getColumns() as $column) {
            $names[$column->getPosition()] = $column->getName();
        }
        ksort($names);

        return array_values($names);
    }

    /** @return list<string> */
    private function columnIds(string $boardId): array
    {
        $this->em->clear();
        $board = $this->em->find(TaskBoard::class, $boardId);
        self::assertInstanceOf(TaskBoard::class, $board);
        $ids = [];
        foreach ($board->getColumns() as $column) {
            $ids[$column->getPosition()] = $column->getId();
        }
        ksort($ids);

        return array_values($ids);
    }
}
