<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Tests\Functional\Sync;

use DateTimeImmutable;
use Nowo\TaskBoardBundle\Entity\Task;
use Nowo\TaskBoardBundle\Entity\TaskChangeHistory;
use Nowo\TaskBoardBundle\Entity\TaskComment;
use Nowo\TaskBoardBundle\Entity\TaskMember;
use Nowo\TaskBoardBundle\Enum\TaskMemberRole;
use Nowo\TaskBoardBundle\Event\TaskBoardEvents;
use Nowo\TaskBoardBundle\Security\TaskBoardResourceAccessChecker;
use Nowo\TaskBoardBundle\Sync\Dto\SyncStatus;
use Nowo\TaskBoardBundle\Sync\Entity\TaskSyncComment;
use Nowo\TaskBoardBundle\Sync\Entity\TaskSyncDirectory;
use Nowo\TaskBoardBundle\Sync\Entity\TaskSyncMember;
use Nowo\TaskBoardBundle\Sync\Entity\TaskSyncPerson;
use Nowo\TaskBoardBundle\Sync\Entity\TaskSyncPersonMapping;
use Nowo\TaskBoardBundle\Sync\People\SyncPeopleWriter;
use Nowo\TaskBoardBundle\Sync\People\TaskSyncPeopleDirectoryService;
use Nowo\TaskBoardBundle\Sync\Provider\RemoteChecklist;
use Nowo\TaskBoardBundle\Sync\Provider\RemoteChecklistItem;
use Nowo\TaskBoardBundle\Sync\Provider\RemoteComment;
use Nowo\TaskBoardBundle\Tests\Functional\App\Entity\AppUser;

use function array_filter;
use function array_map;
use function array_values;
use function json_encode;
use function ksort;
use function sort;
use function str_starts_with;

/**
 * Since 1.25 (§5.2–§5.4, §5.6, §13.1 Writer): creator and creation date, assignees and watchers through the people
 * directory, the projection rule (a mapping never grants access), comments without a prefix, idempotency.
 */
final class SyncPeopleWriterTest extends SyncPeopleFunctionalTestCase
{
    public function testCreatorDateMembersAndCommentsFollowTheDirectoryAndTheProjectionRule(): void
    {
        $this->viewer('carol@example.test');
        $bob   = $this->user('bob@example.test');
        $board = $this->boardWithColumns('To do', 'Done');
        $this->workspace([self::member('9001', 'carol@example.test'), self::member('9002', 'bob@example.test')]);
        $created = new DateTimeImmutable('2024-01-02 10:00:00');
        $this->put(
            $this->row('t1', 'By carol', 1, creator: self::ref('9001', 'carol@example.test'), assignees: [self::ref('9001', 'carol@example.test'), self::ref('9002', 'bob@example.test'), self::ref('9003', 'zed@example.test')], createdAt: $created, watchers: [self::ref('9001', 'carol@example.test'), self::ref('9002', 'bob@example.test')]),
            $this->row('t2', 'By bob', 2, creator: self::ref('9002', 'bob@example.test'), createdAt: new DateTimeImmutable('1990-01-01')),
        );
        $this->provider->setComments('t1', [
            new RemoteComment('c1', 'From carol', null, null, new DateTimeImmutable('@1700000000'), [], self::ref('9001', 'carol@example.test')),
            new RemoteComment('c2', 'From bob', null, null, new DateTimeImmutable('@1700000100'), [], self::ref('9002', 'bob@example.test')),
            new RemoteComment('c3', 'From zed', null, null, new DateTimeImmutable('@1700000200'), [], self::ref('9003')),
        ]);
        $added = 0;
        $this->dispatcher()->addListener(TaskBoardEvents::TASK_MEMBER_ADDED, static function () use (&$added): void {
            ++$added;
        });

        $report = $this->sync($board);
        self::assertSame(SyncStatus::Succeeded, $report->status, (string) json_encode($report->toArray()));
        self::assertSame(2, $report->created);
        self::assertSame(2, $report->written());
        self::assertSame(0, $added, 'the sync notifies nobody');

        $this->em->clear();
        $t1 = $this->taskTitled('By carol');
        self::assertSame('carol@example.test', $this->email($t1->getCreator()));
        self::assertSame($created->getTimestamp(), $t1->getCreatedAt()->getTimestamp());
        self::assertSame(['carol@example.test:assignee', 'carol@example.test:watcher'], $this->members($t1));
        $item = $this->itemOf('t1');
        self::assertSame($this->personOf('9001')->getId(), $item->getCreatorPerson()?->getId());
        self::assertSame([$this->personOf('9002')->getId(), $this->personOf('9003')->getId()], $item->unmatchedPeople('assignee'));
        self::assertSame([$this->personOf('9002')->getId()], $item->unmatchedPeople('watcher'));
        self::assertSame(2, $this->rows(TaskSyncMember::class));

        // Bob is auto-mapped but cannot view the board: never creator, member nor comment author.
        self::assertSame(TaskSyncPersonMapping::Auto, $this->personOf('9002')->getMapping());
        $t2 = $this->taskTitled('By bob');
        self::assertSame('owner@example.com', $this->email($t2->getCreator()));
        self::assertSame($this->personOf('9002')->getId(), $this->itemOf('t2')->getCreatorPerson()?->getId());
        self::assertGreaterThan(946_684_800, $t2->getCreatedAt()->getTimestamp());
        self::assertGreaterThan((new DateTimeImmutable('-1 day'))->getTimestamp(), $t2->getCreatedAt()->getTimestamp(), 'an implausible date is not used');
        $checker = self::getContainer()->get(TaskBoardResourceAccessChecker::class);
        self::assertInstanceOf(TaskBoardResourceAccessChecker::class, $checker);
        $bob = $this->em->find(AppUser::class, $bob->getId());
        self::assertInstanceOf(AppUser::class, $bob);
        foreach ($this->em->getRepository(Task::class)->findAll() as $task) {
            self::assertFalse($checker->canViewTask($bob, $task), $task->getTitle());
        }

        $comments = [];
        foreach ($this->em->getRepository(TaskSyncComment::class)->findAll() as $sync) {
            $comment                        = $sync->getComment();
            $comments[$sync->getRemoteId()] = [$this->email($comment->getAuthor()), $comment->getBody(), $comment->getCreatedAt()->getTimestamp(), $sync->getAuthorPerson()?->getProviderUserId()];
        }
        ksort($comments);
        self::assertSame([
            'c1' => ['carol@example.test', 'From carol', 1_700_000_000, '9001'],
            'c2' => ['owner@example.com', 'From bob', 1_700_000_100, '9002'],
            'c3' => ['owner@example.com', 'From zed', 1_700_000_200, '9003'],
        ], $comments);

        // A second run with no remote change writes nothing.
        $counts = $this->counts();
        $again  = $this->sync($board);
        self::assertSame(SyncStatus::Succeeded, $again->status);
        self::assertSame([0, 0, 2], [$again->created, $again->updated, $again->unchanged]);
        self::assertSame($counts, $this->counts());
    }

    public function testOnlySyncAddedMembersAreRemovedAndCreatorAndDateNeverMove(): void
    {
        $this->viewer('carol@example.test');
        $dave  = $this->viewer('dave@example.test');
        $board = $this->boardWithColumns('To do', 'Done');
        $this->workspace([self::member('9001', 'carol@example.test')]);
        $this->put($this->row('t1', 'Task', 1, creator: self::ref('9001', 'carol@example.test'), assignees: [self::ref('9001', 'carol@example.test')], createdAt: new DateTimeImmutable('2024-01-02')));
        $this->sync($board);

        $this->em->clear();
        $task = $this->taskTitled('Task');
        $dave = $this->em->find(AppUser::class, $dave->getId());
        self::assertInstanceOf(AppUser::class, $dave);
        $task->addMember(new TaskMember($task, $dave, TaskMemberRole::Assignee));
        $this->em->flush();

        $this->put($this->row('t1', 'Task renamed', 2, creator: self::ref('9009', 'nobody@example.test'), assignees: [self::ref('9003')], createdAt: new DateTimeImmutable('2025-05-05')));
        $report = $this->sync($board);
        self::assertSame(1, $report->updated);

        $this->em->clear();
        $task = $this->taskTitled('Task renamed');
        self::assertSame(['dave@example.test:assignee'], $this->members($task), 'the hand-added member stays');
        self::assertSame('carol@example.test', $this->email($task->getCreator()));
        self::assertSame((new DateTimeImmutable('2024-01-02'))->getTimestamp(), $task->getCreatedAt()->getTimestamp());
        self::assertSame(0, $this->rows(TaskSyncMember::class));
        self::assertSame([$this->personOf('9003')->getId()], $this->itemOf('t1')->unmatchedPeople('assignee'));
    }

    public function testAKeptOrAlreadyPresentMemberIsNotAddedTwice(): void
    {
        $this->viewer('carol@example.test');
        $dave  = $this->viewer('dave@example.test');
        $eve   = $this->viewer('eve@example.test');
        $board = $this->boardWithColumns('To do', 'Done');
        $this->workspace([self::member('9001', 'carol@example.test'), self::member('9004', 'dave@example.test')]);
        $this->put($this->row('t1', 'Task', 1, assignees: [self::ref('9001', 'carol@example.test')]));
        $this->sync($board);

        $this->em->clear();
        $task = $this->taskTitled('Task');
        $dave = $this->em->find(AppUser::class, $dave->getId());
        self::assertInstanceOf(AppUser::class, $dave);
        $task->addMember(new TaskMember($task, $dave, TaskMemberRole::Assignee));
        $this->em->flush();
        $this->put($this->row('t1', 'Task 2', 2, assignees: [self::ref('9001', 'carol@example.test'), self::ref('9004', 'dave@example.test')]));
        $this->sync($board);
        $this->em->clear();
        self::assertSame(['carol@example.test:assignee', 'dave@example.test:assignee'], $this->members($this->taskTitled('Task 2')));
        self::assertSame(1, $this->rows(TaskSyncMember::class), 'dave was added by hand: no sync member row');

        // Two passes in one unit of work see the member the first one scheduled.
        $this->refreshOwner();
        $writer    = self::getContainer()->get(SyncPeopleWriter::class);
        $directory = $this->em->getRepository(TaskSyncDirectory::class)->findOneBy([]);
        $service   = self::getContainer()->get(TaskSyncPeopleDirectoryService::class);
        self::assertInstanceOf(SyncPeopleWriter::class, $writer);
        self::assertInstanceOf(TaskSyncDirectory::class, $directory);
        self::assertInstanceOf(TaskSyncPeopleDirectoryService::class, $service);
        $task    = $this->taskTitled('Task 2');
        $session = $service->session($directory, $task->getBoard());
        $refs    = [self::ref('9001', 'carol@example.test'), self::ref('9005', 'eve@example.test')];
        self::assertTrue($writer->syncMembers($session, $this->itemOf('t1'), $task, TaskMemberRole::Assignee, $refs, TaskSyncPerson::KIND_ASSIGNEE, $this->owner));
        self::assertFalse($writer->syncMembers($session, $this->itemOf('t1'), $task, TaskMemberRole::Assignee, $refs, TaskSyncPerson::KIND_ASSIGNEE, $this->owner));
        $this->em->flush();
        self::assertSame(2, $this->rows(TaskSyncMember::class));
        unset($eve);
    }

    public function testA123ItemIsBackFilledOnce(): void
    {
        $this->viewer('carol@example.test');
        $board = $this->boardWithColumns('To do', 'Done');
        $this->workspace();
        $this->put($this->row('t1', 'Old', 1, creator: self::ref('9001', 'carol@example.test'), createdAt: new DateTimeImmutable('2023-03-03')));
        $this->sync($board, people: false);

        $this->em->clear();
        $task = $this->taskTitled('Old');
        self::assertSame('owner@example.com', $this->email($task->getCreator()));
        $this->itemOf('t1')->setBackfillLevel(0);
        $this->em->flush();

        // Unchanged remote: the back-fill still runs once.
        $report = $this->sync($board);
        self::assertSame(1, $report->unchanged);
        $this->em->clear();
        $task = $this->taskTitled('Old');
        self::assertSame('carol@example.test', $this->email($task->getCreator()));
        self::assertSame((new DateTimeImmutable('2023-03-03'))->getTimestamp(), $task->getCreatedAt()->getTimestamp());
        self::assertSame(1, $this->itemOf('t1')->getBackfillLevel());
        self::assertSame('9001', $this->itemOf('t1')->getCreatorPerson()?->getProviderUserId());

        $task->importCreatedAt(new DateTimeImmutable('2020-01-01'));
        $this->em->flush();
        $this->sync($board, full: true);
        $this->em->clear();
        self::assertSame((new DateTimeImmutable('2020-01-01'))->getTimestamp(), $this->taskTitled('Old')->getCreatedAt()->getTimestamp(), 'never back-filled twice');
    }

    public function testABackFillWithoutPeopleOrAPlausibleDateOnlyStampsTheLevel(): void
    {
        $board = $this->boardWithColumns('To do', 'Done');
        $this->put($this->row('t1', 'Old', 1));
        $this->sync($board);
        $this->em->clear();
        $before = $this->taskTitled('Old')->getCreatedAt()->getTimestamp();
        $this->itemOf('t1')->setBackfillLevel(0);
        $this->em->flush();

        $this->sync($board);
        $this->em->clear();
        self::assertSame(1, $this->itemOf('t1')->getBackfillLevel());
        self::assertSame($before, $this->taskTitled('Old')->getCreatedAt()->getTimestamp());
        self::assertNull($this->itemOf('t1')->getCreatorPerson());
    }

    public function testRowsWithoutWatchersOrChecklistsReadTheTaskDetailWithinTheCap(): void
    {
        $this->viewer('carol@example.test');
        $provider = new DetailTaskProvider();
        $provider->withWorkspace(self::WORKSPACE);
        $board = $this->boardWithColumns('To do', 'Done');
        $provider->putTask(self::LIST, $this->row('t1', 'One', 1));
        $provider->putTask(self::LIST, $this->row('t2', 'Two', 2));
        $provider->withTaskDetail($this->row('t1', 'One', 1, watchers: [self::ref('9001', 'carol@example.test')], checklists: [new RemoteChecklist('cl1', 'Steps', 0, [new RemoteChecklistItem('i1', 'Step one', 0)])]));

        $report = $this->sync($board, $provider);
        self::assertSame(SyncStatus::Succeeded, $report->status, (string) json_encode($report->toArray()));
        self::assertSame(['fetchTaskDetail:t1', 'fetchTaskDetail:t2'], array_values(array_filter($provider->calls, static fn (string $c): bool => str_starts_with($c, 'fetchTaskDetail'))));
        self::assertSame(2, $report->stat('detail.calls'));
        $this->em->clear();
        self::assertSame(['carol@example.test:watcher'], $this->members($this->taskTitled('One')));
        self::assertSame('One', $this->taskTitled('Step one')->getParent()?->getTitle());
    }

    /**
     * @return array<string, int>
     */
    private function counts(): array
    {
        $this->em->clear();
        $counts = [];
        foreach ([Task::class, TaskComment::class, TaskMember::class, TaskSyncPerson::class, TaskSyncMember::class, TaskSyncComment::class, TaskChangeHistory::class] as $class) {
            $counts[$class] = $this->rows($class);
        }

        return $counts;
    }

    /**
     * @return list<string>
     */
    private function members(Task $task): array
    {
        $members = array_map(fn (TaskMember $m): string => $this->email($m->getUser()) . ':' . $m->getMemberRole()->value, $task->getMembers()->toArray());
        sort($members);

        return $members;
    }

    private function email(object $user): string
    {
        self::assertInstanceOf(AppUser::class, $user);

        return $user->getEmail();
    }
}
