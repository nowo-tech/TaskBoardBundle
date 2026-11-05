<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Tests\Functional\Sync;

use DateTimeImmutable;
use Nowo\TaskBoardBundle\Enum\TaskMemberRole;
use Nowo\TaskBoardBundle\Sync\Entity\TaskSyncPersonMapping;
use Nowo\TaskBoardBundle\Sync\Event\SyncPeopleReprojectedEvent;
use Nowo\TaskBoardBundle\Sync\Event\SyncPersonAnonymisedEvent;
use Nowo\TaskBoardBundle\Sync\Event\SyncPersonMappedEvent;
use Nowo\TaskBoardBundle\Sync\People\ReassignCursor;
use Nowo\TaskBoardBundle\Sync\People\SyncUser;
use Nowo\TaskBoardBundle\Sync\Repository\SyncStore;
use Nowo\TaskBoardBundle\Tests\Functional\App\Entity\AppUser;

use function array_filter;
use function array_values;

/**
 * Since 1.25 (§5.7, §9): re-projection when someone gains board access (only where they can view the board now,
 * idempotent, nothing deleted when access is lost) and the account-erasure helper `detachUser()`.
 */
final class SyncProjectorPeopleTest extends SyncReassignTestCase
{
    private AppUser $alice;

    protected function setUp(): void
    {
        parent::setUp();
        $this->alice = $this->user('alice@example.test');
    }

    public function testReprojectionWritesRowsOnlyWhereTheUserCanViewTheBoardNow(): void
    {
        $directory = $this->directory();
        $seen      = $this->board('Seen');
        $hidden    = $this->board('Hidden');
        $person    = $this->person($directory, '9001', $this->alice);
        $visible   = $this->item($this->connect($seen, $directory), 'T1', $person, $this->owner, $this->owner, null, [TaskMemberRole::Assignee, TaskMemberRole::Watcher]);
        $secret    = $this->item($this->connect($hidden, $directory), 'T2', $person, $this->owner, $this->owner, null, [TaskMemberRole::Assignee]);
        $this->viewer($seen, $this->alice);

        $report = $this->projector()->reprojectForUser($this->alice, null, 500, null, 'Membership_Added!');

        self::assertTrue($report->isDone());
        self::assertSame(1, $report->notProjected);
        self::assertSame(1, $report->creators);
        self::assertSame(2, $report->membersAdded);
        $aliceId = (string) $this->alice->getId();
        $task    = $this->taskOf($visible);
        self::assertSame($aliceId, SyncUser::id($task->getCreator()));
        self::assertSame(['assignee:' . $aliceId, 'watcher:' . $aliceId], $this->membersOf($task));
        self::assertSame($aliceId, $this->authorOf($visible));
        self::assertSame([], $this->freshItem($visible)->unmatchedPeople('assignee'));
        // The board Alice cannot view is never written: a mapping never grants access.
        $hiddenTask = $this->taskOf($secret);
        self::assertSame((string) $this->owner->getId(), SyncUser::id($hiddenTask->getCreator()));
        self::assertSame([], $this->membersOf($hiddenTask));
        self::assertSame((string) $this->owner->getId(), $this->authorOf($secret));
        self::assertSame([$person->getId()], $this->freshItem($secret)->unmatchedPeople('assignee'));
        self::assertFalse($this->accessChecker()->canViewTask($this->freshUser($this->alice), $hiddenTask));

        $events = $this->reprojected();
        self::assertCount(1, $events);
        self::assertSame('membership_added', $events[0]->trigger);
        self::assertSame($aliceId, $events[0]->userId);
        self::assertContains($seen->getId(), $events[0]->boardIds);
        self::assertSame(1, $events[0]->report->creators);

        // Idempotent: a second call writes nothing.
        $again = $this->projector()->reprojectForUser($this->freshUser($this->alice), null, 500, null, '!!!');
        self::assertSame(0, $again->tasks);
        self::assertSame('membership', $this->reprojected()[1]->trigger);

        // Losing access deletes nothing: the rows stay (access is board-driven).
        $this->viewers = [];
        self::assertSame(0, $this->projector()->reprojectForUser($this->freshUser($this->alice))->tasks);
        self::assertSame($aliceId, SyncUser::id($this->taskOf($visible)->getCreator()));
    }

    public function testReprojectionOnOneBoardIsResumableAndWaitsForABusyBoard(): void
    {
        $directory  = $this->directory();
        $board      = $this->board('Seen');
        $other      = $this->board('Other');
        $person     = $this->person($directory, '9001', $this->alice);
        $connection = $this->connect($board, $directory);
        $this->connect($other, $directory);
        $first  = $this->item($connection, 'T1', $person, $this->owner);
        $second = $this->item($connection, 'T2', $person, $this->owner);
        $this->viewer($board, $this->alice);
        $this->viewer($other, $this->alice);

        $step = $this->projector()->reprojectForUser($this->alice, $board, 1);
        self::assertFalse($step->isDone());
        self::assertInstanceOf(ReassignCursor::class, $step->cursor);
        self::assertSame([], $this->reprojected());

        // Busy: a sync holds the board's lock.
        $store = new SyncStore($this->em);
        $now   = new DateTimeImmutable();
        self::assertTrue($store->acquireLock($connection, 'a-sync-run', $now, $now->modify('-10 minutes')));
        $busy = $this->projector()->reprojectForUser($this->alice, $this->freshBoardOf($board), 1, $step->cursor);
        self::assertSame(1, $busy->busyBoards);
        $store->releaseLock($connection->getId(), 'a-sync-run');

        $report = $busy;
        while (!$report->isDone()) {
            $report = $this->projector()->reprojectForUser($this->freshUser($this->alice), $this->freshBoardOf($board), 1, $report->cursor);
        }
        foreach ([$first, $second] as $item) {
            self::assertSame((string) $this->alice->getId(), SyncUser::id($this->taskOf($item)->getCreator()));
        }
        $events = $this->reprojected();
        self::assertCount(1, $events);
        self::assertSame([$board->getId()], $events[0]->boardIds);
    }

    public function testDetachUserMovesEverythingBackAndForgetsThePeople(): void
    {
        $board      = $this->board();
        $connection = $this->connect($board, $directory = $this->directory());
        $mapped     = $this->person($directory, '9001', $this->alice);
        $suggested  = $this->person($directory, '9002', $this->alice, TaskSyncPersonMapping::Suggested);
        $first      = $this->item($connection, 'T1', $mapped, $this->alice, $this->alice, $this->alice, [TaskMemberRole::Assignee, TaskMemberRole::Watcher]);
        $second     = $this->item($connection, 'T2', $mapped, $this->alice, $this->alice, $this->alice);
        $this->viewer($board, $this->alice);

        // Resumable: one item per call.
        $report = $this->reassigner()->detachUser($this->alice, $this->owner, true, 1);
        self::assertFalse($report->isDone());
        while (!$report->isDone()) {
            $report = $this->reassigner()->detachUser($this->freshUser($this->alice), $this->owner, true, 1, $report->cursor);
        }

        $ownerId = (string) $this->owner->getId();
        foreach ([$first, $second] as $item) {
            $task = $this->taskOf($item);
            self::assertSame($ownerId, SyncUser::id($task->getCreator()));
            self::assertSame([], $this->membersOf($task));
            self::assertSame($ownerId, $this->authorOf($item));
            self::assertSame([$mapped->getId()], $this->freshItem($item)->unmatchedPeople('assignee'));
        }
        foreach ([$mapped, $suggested] as $person) {
            $fresh = $this->freshPerson($person);
            self::assertSame(TaskSyncPersonMapping::External, $fresh->getMapping());
            self::assertTrue($fresh->isAnonymised());
            self::assertNull($fresh->getEmail());
        }
        $erased = array_values(array_filter($this->events, static fn (object $e): bool => $e instanceof SyncPersonMappedEvent && $e->reason === SyncPersonMappedEvent::REASON_ERASED));
        self::assertCount(2, $erased);
        self::assertCount(2, array_filter($this->events, static fn (object $e): bool => $e instanceof SyncPersonAnonymisedEvent));

        // Idempotent: nothing is linked to the user any more.
        self::assertSame(0, $this->reassigner()->detachUser($this->freshUser($this->alice), $this->owner)->tasks);
    }

    public function testDetachUserWithoutAnonymisingKeepsTheNames(): void
    {
        $board      = $this->board();
        $connection = $this->connect($board, $directory = $this->directory());
        $person     = $this->person($directory, '9001', $this->alice);
        $this->item($connection, 'T1', $person, $this->alice);

        self::assertTrue($this->reassigner()->detachUser($this->alice, $this->owner)->isDone());
        $fresh = $this->freshPerson($person);
        self::assertSame(TaskSyncPersonMapping::External, $fresh->getMapping());
        self::assertFalse($fresh->isAnonymised());
        self::assertSame('User 9001', $fresh->getName());
    }

    /**
     * @return list<SyncPeopleReprojectedEvent>
     */
    private function reprojected(): array
    {
        return array_values(array_filter($this->events, static fn (object $e): bool => $e instanceof SyncPeopleReprojectedEvent));
    }
}
