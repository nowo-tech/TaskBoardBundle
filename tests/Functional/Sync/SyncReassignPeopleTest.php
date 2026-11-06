<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Tests\Functional\Sync;

use DateTimeImmutable;
use Nowo\TaskBoardBundle\Entity\Task;
use Nowo\TaskBoardBundle\Entity\TaskChangeHistory;
use Nowo\TaskBoardBundle\Entity\TaskMember;
use Nowo\TaskBoardBundle\Enum\TaskChangeType;
use Nowo\TaskBoardBundle\Enum\TaskMemberRole;
use Nowo\TaskBoardBundle\Sync\Entity\TaskSyncItem;
use Nowo\TaskBoardBundle\Sync\Entity\TaskSyncMember;
use Nowo\TaskBoardBundle\Sync\Entity\TaskSyncPersonMapping;
use Nowo\TaskBoardBundle\Sync\Event\SyncPersonMappedEvent;
use Nowo\TaskBoardBundle\Sync\Event\SyncPersonReassignedEvent;
use Nowo\TaskBoardBundle\Sync\People\InvalidMappingException;
use Nowo\TaskBoardBundle\Sync\People\PeopleMappingDeniedException;
use Nowo\TaskBoardBundle\Sync\People\ReassignCursor;
use Nowo\TaskBoardBundle\Sync\People\ReassignInProgressException;
use Nowo\TaskBoardBundle\Sync\People\ReassignReport;
use Nowo\TaskBoardBundle\Sync\People\SyncUser;
use Nowo\TaskBoardBundle\Sync\Repository\SyncStore;
use Nowo\TaskBoardBundle\Tests\Functional\App\Entity\AppUser;

use function array_filter;
use function array_values;

/**
 * Since 1.25 (§2.4, §13.1 "Reassigner"): re-association moves creator, comment authors and sync-added members (checklist
 * subtasks included) on every board of the directory, keeps hand-added members, is resumable and audited.
 */
final class SyncReassignPeopleTest extends SyncReassignTestCase
{
    private AppUser $alice;

    private AppUser $bob;

    private AppUser $carol;

    protected function setUp(): void
    {
        parent::setUp();
        $this->alice = $this->user('alice@example.test');
        $this->bob   = $this->user('bob@example.test');
        $this->carol = $this->user('carol@example.test');
    }

    public function testAToBMovesCreatorCommentsAndSyncMembersAndKeepsHandAddedOnes(): void
    {
        $board      = $this->board();
        $connection = $this->connect($board, $directory = $this->directory());
        $person     = $this->person($directory, '9001', $this->alice);
        $item       = $this->item($connection, 'T1', $person, $this->alice, $this->alice, $this->alice, [TaskMemberRole::Assignee, TaskMemberRole::Watcher]);
        $task       = $item->getTask();
        self::assertInstanceOf(Task::class, $task);
        $hand = new TaskMember($task, $this->carol, TaskMemberRole::Assignee);
        $task->addMember($hand);
        $this->em->persist($hand);
        $this->em->flush();
        // A checklist item (a subtask) whose assignee is the person.
        $check = $this->item($connection, 'T1#c1', $person, $this->alice, null, $this->alice, [TaskMemberRole::Assignee], $task);
        $check->setItemKind(TaskSyncItem::KIND_CHECKLIST_ITEM);
        $this->em->flush();
        $this->viewer($board, $this->alice, $this->bob);

        $report = $this->reassigner()->reassign($person, $this->bob, $this->owner);

        self::assertTrue($report->isDone());
        self::assertSame(['tasks' => 2, 'creators' => 2, 'comments' => 1, 'membersRemoved' => 3, 'membersAdded' => 3, 'notProjected' => 0, 'busyBoards' => 0, 'boards' => 0, 'documents' => 0, 'timeEntries' => 0], $report->toArray());
        $bobId   = (string) $this->bob->getId();
        $aliceId = (string) $this->alice->getId();
        $fresh   = $this->taskOf($item);
        self::assertSame($bobId, SyncUser::id($fresh->getCreator()));
        self::assertSame(['assignee:' . $bobId, 'assignee:' . $this->carol->getId(), 'watcher:' . $bobId], $this->membersOf($fresh));
        self::assertSame($bobId, $this->authorOf($item));
        $history = $this->history($fresh);
        self::assertCount(1, $history);
        self::assertSame('user:' . $aliceId, $history[0]->getOldValue());
        self::assertSame('user:' . $bobId, $history[0]->getNewValue());
        self::assertSame('clickup', $history[0]->getContext());
        self::assertSame(['assignee:' . $bobId], $this->membersOf($this->taskOf($check)));
        self::assertSame(3, $this->em->getRepository(TaskSyncMember::class)->count([]));

        $person = $this->freshPerson($person);
        self::assertSame(TaskSyncPersonMapping::Manual, $person->getMapping());
        self::assertSame($bobId, SyncUser::id($person->getMappedUser()));
        self::assertNull($person->getReassignId());

        $reassigned = $this->eventsOf(SyncPersonReassignedEvent::class);
        self::assertCount(1, $reassigned);
        self::assertSame($aliceId, $reassigned[0]->previousUserId);
        self::assertSame($bobId, $reassigned[0]->newUserId);
        self::assertSame((string) $this->owner->getId(), $reassigned[0]->actorId);
        self::assertSame($person->getId(), $reassigned[0]->personId);
        self::assertSame($directory->getId(), $reassigned[0]->directoryId);
        $mapped = $this->eventsOf(SyncPersonMappedEvent::class);
        self::assertCount(1, $mapped);
        self::assertSame(SyncPersonMappedEvent::REASON_MANUAL, $mapped[0]->reason);

        // A second call with the same target writes nothing (idempotent) and does not map again.
        $this->events = [];
        $again        = $this->reassigner()->reassign($this->freshPerson($person), $this->freshUser($this->bob), $this->owner);
        self::assertSame(0, $again->tasks);
        self::assertSame([], $this->eventsOf(SyncPersonMappedEvent::class));
        self::assertCount(1, $this->eventsOf(SyncPersonReassignedEvent::class));
    }

    public function testAToExternalPutsTheContentBackOnTheActorPlusTheReference(): void
    {
        $board      = $this->board();
        $connection = $this->connect($board, $directory = $this->directory());
        $person     = $this->person($directory, '9001', $this->alice);
        $item       = $this->item($connection, 'T1', $person, $this->alice, $this->alice, $this->alice, [TaskMemberRole::Assignee, TaskMemberRole::Watcher]);
        $this->viewer($board, $this->alice);

        $report = $this->reassigner()->reassign($person, null, $this->owner);

        self::assertSame(1, $report->creators);
        self::assertSame(2, $report->membersRemoved);
        $fresh = $this->taskOf($item);
        self::assertSame((string) $this->owner->getId(), SyncUser::id($fresh->getCreator()));
        self::assertSame([], $this->membersOf($fresh));
        self::assertSame((string) $this->owner->getId(), $this->authorOf($item));
        $item = $this->freshItem($item);
        self::assertSame([$person->getId()], $item->unmatchedPeople('assignee'));
        self::assertSame([$person->getId()], $item->unmatchedPeople('watcher'));
        self::assertSame('person:' . $person->getId(), $this->history($fresh)[0]->getNewValue());
        self::assertSame(TaskSyncPersonMapping::External, $this->freshPerson($person)->getMapping());
        self::assertNull($this->eventsOf(SyncPersonReassignedEvent::class)[0]->newUserId);
    }

    public function testExternalToAProjectsTheReferencesIntoRows(): void
    {
        $board      = $this->board();
        $connection = $this->connect($board, $directory = $this->directory());
        $person     = $this->person($directory, '9001', null, TaskSyncPersonMapping::External);
        $item       = $this->item($connection, 'T1', $person, $this->owner, $this->owner, null, [TaskMemberRole::Assignee]);
        $this->viewer($board, $this->alice);

        $report = $this->reassigner()->reassign($person, $this->alice, $this->owner);

        self::assertSame(['tasks' => 1, 'creators' => 1, 'comments' => 1, 'membersRemoved' => 0, 'membersAdded' => 1, 'notProjected' => 0, 'busyBoards' => 0, 'boards' => 0, 'documents' => 0, 'timeEntries' => 0], $report->toArray());
        $fresh = $this->taskOf($item);
        self::assertSame((string) $this->alice->getId(), SyncUser::id($fresh->getCreator()));
        self::assertSame(['assignee:' . $this->alice->getId()], $this->membersOf($fresh));
        self::assertSame([], $this->freshItem($item)->unmatchedPeople('assignee'));
        self::assertSame('user:' . $this->owner->getId(), $this->history($fresh)[0]->getOldValue());
        self::assertNull($this->eventsOf(SyncPersonReassignedEvent::class)[0]->previousUserId);
    }

    public function testATargetWhoCannotViewABoardIsNotProjectedThere(): void
    {
        $board      = $this->board();
        $connection = $this->connect($board, $directory = $this->directory());
        $person     = $this->person($directory, '9001', $this->alice);
        $item       = $this->item($connection, 'T1', $person, $this->alice, $this->alice, $this->alice);
        // Bob is a candidate but cannot view the board: the mapping never grants access.

        $report = $this->reassigner()->reassign($person, $this->bob, $this->owner);

        self::assertSame(1, $report->notProjected);
        $fresh = $this->taskOf($item);
        self::assertSame((string) $this->owner->getId(), SyncUser::id($fresh->getCreator()));
        self::assertSame([], $this->membersOf($fresh));
        self::assertFalse($this->accessChecker()->canViewTask($this->freshUser($this->bob), $fresh));
        self::assertSame([$person->getId()], $this->freshItem($item)->unmatchedPeople('assignee'));
        self::assertSame((string) $this->bob->getId(), SyncUser::id($this->freshPerson($person)->getMappedUser()));
    }

    public function testAHandAddedMemberOfTheTargetIsNotDuplicated(): void
    {
        $board      = $this->board();
        $connection = $this->connect($board, $directory = $this->directory());
        $person     = $this->person($directory, '9001', $this->alice);
        $item       = $this->item($connection, 'T1', $person, $this->alice, null, $this->alice);
        $task       = $item->getTask();
        self::assertInstanceOf(Task::class, $task);
        $hand = new TaskMember($task, $this->bob, TaskMemberRole::Assignee);
        $task->addMember($hand);
        $this->em->persist($hand);
        $this->em->flush();
        $this->viewer($board, $this->alice, $this->bob);

        $report = $this->reassigner()->reassign($person, $this->bob, $this->owner);

        self::assertSame(1, $report->membersRemoved);
        self::assertSame(0, $report->membersAdded);
        self::assertSame(['assignee:' . $this->bob->getId()], $this->membersOf($this->taskOf($item)));
    }

    public function testRefusals(): void
    {
        $board      = $this->board();
        $connection = $this->connect($board, $directory = $this->directory());
        $person     = $this->person($directory, '9001', $this->alice);
        $this->item($connection, 'T1', $person, $this->alice);
        $this->viewer($board, $this->alice, $this->bob);

        // Not a candidate.
        $this->nonCandidates = ['bob@example.test'];
        try {
            $this->reassigner()->reassign($person, $this->bob, $this->owner);
            self::fail('not a candidate');
        } catch (InvalidMappingException $exception) {
            self::assertSame('not_candidate', $exception->reason);
        }
        // A resumed call re-checks the mapped user on every board.
        $person->map(TaskSyncPersonMapping::Manual, $this->bob, null, new DateTimeImmutable());
        $this->em->flush();
        try {
            $this->reassigner()->reassign($person, $this->bob, $this->owner, null, new ReassignCursor('r-1'));
            self::fail('resumed, not a candidate');
        } catch (InvalidMappingException $exception) {
            self::assertSame('not_candidate', $exception->reason);
        }
        $this->nonCandidates = [];

        // An actor who cannot manage every board of the directory (a second board of Bob's).
        $other = $this->board('Other', creator: $this->bob);
        $this->connect($other, $directory);
        try {
            $this->reassigner()->reassign($person, $this->alice, $this->owner);
            self::fail('denied');
        } catch (PeopleMappingDeniedException) {
        }

        // An anonymised person is never mapped to a user again.
        $solo   = $this->directory();
        $lonely = $this->board('Solo');
        $this->connect($lonely, $solo);
        $gone = $this->person($solo, '9002', $this->alice);
        $gone->anonymise(new DateTimeImmutable());
        $this->em->flush();
        try {
            $this->reassigner()->reassign($gone, $this->alice, $this->owner);
            self::fail('anonymised');
        } catch (InvalidMappingException $exception) {
            self::assertSame('anonymised', $exception->reason);
        }
        // … but it may still be kept as external.
        self::assertTrue($this->reassigner()->reassign($gone, null, $this->owner)->isDone());
        self::assertSame(TaskSyncPersonMapping::External, $this->freshPerson($gone)->getMapping());
    }

    public function testABatchedReassignResumesWithItsCursorAndAfterARestart(): void
    {
        $board      = $this->board();
        $connection = $this->connect($board, $directory = $this->directory());
        $person     = $this->person($directory, '9001', $this->alice);
        $first      = $this->item($connection, 'T1', $person, $this->alice, null, $this->alice);
        $second     = $this->item($connection, 'T2', $person, $this->alice, null, $this->alice);
        $this->viewer($board, $this->alice, $this->bob, $this->carol);

        $step = $this->reassigner()->reassign($person, $this->bob, $this->owner, 1);
        self::assertFalse($step->isDone());
        self::assertInstanceOf(ReassignCursor::class, $step->cursor);
        self::assertSame(1, $step->tasks);
        self::assertEquals($step->cursor, ReassignCursor::fromArray($step->cursor->toArray()));
        $reassignId = $step->cursor->reassignId;
        self::assertSame($reassignId, $this->freshPerson($person)->getReassignId());
        self::assertSame((string) $this->bob->getId(), SyncUser::id($this->freshPerson($person)->getReassignToUser()));
        self::assertFalse($this->freshPerson($person)->isReassignExternal());
        self::assertSame([], $this->eventsOf(SyncPersonReassignedEvent::class));

        // Another target while it is unfinished is refused.
        try {
            $this->reassigner()->reassign($this->freshPerson($person), $this->freshUser($this->carol), $this->owner);
            self::fail('in progress');
        } catch (ReassignInProgressException $exception) {
            self::assertSame('reassign_in_progress', $exception->getMessage());
        }
        try {
            $this->reassigner()->reassign($this->freshPerson($person), null, $this->owner);
            self::fail('in progress (external)');
        } catch (ReassignInProgressException) {
        }

        // A worker restart (no cursor): the same target resumes under the same id.
        $report = $this->reassigner()->reassign($this->freshPerson($person), $this->freshUser($this->bob), $this->owner, 1);
        while (!$report->isDone()) {
            $report = $this->reassigner()->reassign($this->freshPerson($person), null, $this->owner, 1, $report->cursor);
        }
        $events = $this->eventsOf(SyncPersonReassignedEvent::class);
        self::assertCount(1, $events);
        self::assertSame($reassignId, $events[0]->reassignId);
        foreach ([$first, $second] as $item) {
            self::assertSame((string) $this->bob->getId(), SyncUser::id($this->taskOf($item)->getCreator()));
        }
        self::assertNull($this->freshPerson($person)->getReassignId());
    }

    public function testCancelForgetsAnUnfinishedReassign(): void
    {
        $board      = $this->board();
        $connection = $this->connect($board, $directory = $this->directory());
        $person     = $this->person($directory, '9001', $this->alice);
        $this->item($connection, 'T1', $person, $this->alice);
        $this->item($connection, 'T2', $person, $this->alice);
        $this->viewer($board, $this->alice, $this->bob, $this->carol);

        $step = $this->reassigner()->reassign($person, null, $this->owner, 1);
        self::assertFalse($step->isDone());
        self::assertTrue($this->freshPerson($person)->isReassignExternal());

        $this->reassigner()->cancelReassign($this->freshPerson($person), $this->owner);
        $person = $this->freshPerson($person);
        self::assertNull($person->getReassignId());
        self::assertNull($person->getReassignToUser());
        self::assertFalse($person->isReassignExternal());
        self::assertTrue($this->reassigner()->reassign($person, $this->freshUser($this->carol), $this->owner)->isDone());

        $intruder = $this->user('mallory@example.test');
        $this->expectException(PeopleMappingDeniedException::class);
        $this->reassigner()->cancelReassign($this->freshPerson($person), $intruder);
    }

    public function testABusyBoardIsReportedAndResumedLater(): void
    {
        $board      = $this->board();
        $connection = $this->connect($board, $directory = $this->directory());
        $person     = $this->person($directory, '9001', $this->alice);
        $item       = $this->item($connection, 'T1', $person, $this->alice);
        $this->viewer($board, $this->alice, $this->bob);
        $store = new SyncStore($this->em);
        $now   = new DateTimeImmutable();
        self::assertTrue($store->acquireLock($connection, 'a-sync-run', $now, $now->modify('-10 minutes')));

        $busy = $this->reassigner()->reassign($person, $this->bob, $this->owner);
        self::assertSame(1, $busy->busyBoards);
        self::assertInstanceOf(ReassignCursor::class, $busy->cursor);
        self::assertSame((string) $this->alice->getId(), SyncUser::id($this->taskOf($item)->getCreator()));

        $store->releaseLock($connection->getId(), 'a-sync-run');
        $done = $this->reassigner()->reassign($this->freshPerson($person), null, $this->owner, null, $busy->cursor);
        self::assertTrue($done->isDone());
        self::assertSame((string) $this->bob->getId(), SyncUser::id($this->taskOf($item)->getCreator()));
        self::assertSame((string) $this->alice->getId(), $this->eventsOf(SyncPersonReassignedEvent::class)[0]->previousUserId);
    }

    public function testEveryBoardOfTheDirectoryIsWalked(): void
    {
        $directory = $this->directory();
        $boards    = [$this->board('One'), $this->board('Two')];
        $person    = $this->person($directory, '9001', $this->alice);
        $items     = [];
        foreach ($boards as $index => $board) {
            $items[] = $this->item($this->connect($board, $directory), 'T' . $index, $person, $this->alice, $this->alice, $this->alice);
            $this->viewer($board, $this->alice, $this->bob);
        }

        $report = $this->reassigner()->reassign($person, $this->bob, $this->owner);

        self::assertSame(2, $report->tasks);
        foreach ($items as $item) {
            self::assertSame((string) $this->bob->getId(), SyncUser::id($this->taskOf($item)->getCreator()));
        }
    }

    public function testCursorAndReportValueObjects(): void
    {
        $cursor = new ReassignCursor('r', 'u', 1, 2, 'i');
        self::assertEquals($cursor, ReassignCursor::fromArray($cursor->toArray()));
        self::assertEquals(new ReassignCursor(''), ReassignCursor::fromArray(['previousUserId' => ['x'], 'personIndex' => 'n']));
        $report = (new ReassignReport(1, 2, 3, 4, 5, 6, 7))->plus(new ReassignReport(1, 1, 1, 1, 1, 1, 1, $cursor));
        self::assertSame(['tasks' => 2, 'creators' => 3, 'comments' => 4, 'membersRemoved' => 5, 'membersAdded' => 6, 'notProjected' => 7, 'busyBoards' => 8, 'boards' => 0, 'documents' => 0, 'timeEntries' => 0], $report->toArray());
        self::assertFalse($report->isDone());
        self::assertTrue($report->withCursor(null)->isDone());
    }

    /**
     * @return list<TaskChangeHistory>
     */
    private function history(Task $task): array
    {
        return array_values(array_filter($task->getChangeHistory()->toArray(), static fn (TaskChangeHistory $entry): bool => $entry->getChangeType() === TaskChangeType::SyncPersonReassociated));
    }

    /**
     * @template T of object
     *
     * @param class-string<T> $class
     *
     * @return list<T>
     */
    private function eventsOf(string $class): array
    {
        return array_values(array_filter($this->events, static fn (object $event): bool => $event instanceof $class));
    }
}
