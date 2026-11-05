<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Sync\People;

use DateInterval;
use Doctrine\ORM\EntityManagerInterface;
use Nowo\TaskBoardBundle\Entity\TaskBoard;
use Nowo\TaskBoardBundle\Event\TaskBoardEvents;
use Nowo\TaskBoardBundle\Security\TaskBoardResourceAccessChecker;
use Nowo\TaskBoardBundle\Sync\Entity\TaskBoardConnection;
use Nowo\TaskBoardBundle\Sync\Entity\TaskSyncPerson;
use Nowo\TaskBoardBundle\Sync\Entity\TaskSyncPersonMapping;
use Nowo\TaskBoardBundle\Sync\Entity\TaskSyncRun;
use Nowo\TaskBoardBundle\Sync\Event\SyncPeopleReprojectedEvent;
use Nowo\TaskBoardBundle\Sync\Event\SyncPersonMappedEvent;
use Nowo\TaskBoardBundle\Sync\Event\SyncPersonReassignedEvent;
use Nowo\TaskBoardBundle\Sync\Hook\SyncPersonResolverInterface;
use Nowo\TaskBoardBundle\Sync\Repository\SyncStore;
use Psr\Clock\ClockInterface;
use Symfony\Component\Clock\NativeClock;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

use function array_keys;
use function count;
use function max;

/**
 * Since 1.25: re-association (§2.4 / §5.7 of the contract). `reassign()` maps a person to another user (or external)
 * and moves what was already imported, on every board of the directory; `reprojectForUser()` turns references into
 * real rows once a user can view a board; `detachUser()` is the account-erasure helper. Batched and resumable (call
 * again with the returned cursor until it is null); each board is processed under its sync lock; no notification.
 * Losing board access deletes nothing: rows simply stop granting access, because access is board-driven.
 */
final readonly class TaskSyncPeopleReassigner
{
    private const STALE_AFTER = 'PT10M';

    public function __construct(
        private EntityManagerInterface $entityManager,
        private TaskSyncPersonRepository $people,
        private TaskSyncPeopleDirectoryService $directories,
        private PeopleProjection $projection,
        private SyncStore $store,
        private SyncPersonResolverInterface $resolver,
        private TaskBoardResourceAccessChecker $accessChecker,
        private ?EventDispatcherInterface $eventDispatcher = null,
        private ClockInterface $clock = new NativeClock(),
        private int $defaultBatchSize = 500,
    ) {
    }

    /**
     * Maps the person to `$newUser` (null = keep as external) and moves creator, comment authors and sync members on
     * every board of its directory. The actor must manage every board; the new user must be a candidate of each.
     *
     * @throws PeopleMappingDeniedException
     * @throws InvalidMappingException
     */
    public function reassign(TaskSyncPerson $person, ?object $newUser, object $actor, ?int $batchSize = null, ?ReassignCursor $cursor = null): ReassignReport
    {
        $directory = $person->getDirectory();
        $boards    = $this->directories->assertCanManage($directory, $actor, null);
        if (!$cursor instanceof ReassignCursor) {
            if ($person->isAnonymised() && $newUser !== null) {
                throw new InvalidMappingException('anonymised');
            }
            if ($newUser !== null) {
                $newUser = $this->directories->candidate($boards, (string) SyncUser::id($newUser));
            }
            $pending = $person->getReassignId();
            if ($pending !== null && ($person->isReassignExternal() !== ($newUser === null) || ($newUser !== null && !SyncUser::same($person->getReassignToUser(), $newUser)))) {
                throw new ReassignInProgressException();
            }
            // A re-association left unfinished (worker restart) resumes under its id; the walk is idempotent.
            $cursor = new ReassignCursor($pending ?? TaskSyncRun::newId(), SyncUser::id($person->getMappedUser()));
            $person->setPendingReassign($cursor->reassignId, $newUser, $newUser === null);
            $mapping = $newUser === null ? TaskSyncPersonMapping::External : TaskSyncPersonMapping::Manual;
            if ($person->getMapping() !== $mapping || !SyncUser::same($person->getMappedUser(), $newUser)) {
                $this->directories->changeMapping($person, $mapping, $newUser, $actor, $newUser === null ? SyncPersonMappedEvent::REASON_EXTERNAL : SyncPersonMappedEvent::REASON_MANUAL);
            }
            $this->entityManager->flush();
        }
        $target = $person->getMappedUser();
        if ($target !== null) {
            foreach ($boards as $board) {
                if (!$this->resolver->isCandidate($board, $target)) {
                    throw new InvalidMappingException('not_candidate');
                }
            }
        }

        $report = $this->walk($person, $target, $actor, $this->people->connectionsOf($directory), $batchSize, $cursor);
        if ($report->isDone()) {
            $person->setPendingReassign(null, null, false);
            $this->entityManager->flush();
            $this->eventDispatcher?->dispatch(new SyncPersonReassignedEvent($directory->getId(), $person->getId(), $cursor->previousUserId, SyncUser::id($target), SyncUser::id($actor), $cursor->reassignId, $report), TaskBoardEvents::SYNC_PERSON_REASSIGNED);
        }

        return $report;
    }

    /**
     * Forgets an unfinished re-association (what it already moved stays; call `reassign()` again to finish or undo).
     *
     * @throws PeopleMappingDeniedException
     */
    public function cancelReassign(TaskSyncPerson $person, object $actor): void
    {
        $this->directories->assertCanManage($person->getDirectory(), $actor, null);
        $person->setPendingReassign(null, null, false);
        $this->entityManager->flush();
    }

    /**
     * Turns the references of every person mapped (auto / manual) to `$user` into real rows on the boards the user can
     * view **now** (all boards of their directories, or only `$board`). Called by the host on its membership events;
     * idempotent; audited by {@see SyncPeopleReprojectedEvent}.
     */
    public function reprojectForUser(object $user, ?TaskBoard $board = null, string $trigger = 'membership', ?int $batchSize = null, ?ReassignCursor $cursor = null): ReassignReport
    {
        $cursor ??= new ReassignCursor(TaskSyncRun::newId());
        $people = $this->people->linkedToUser($user, false);
        $total  = new ReassignReport();
        $boards = [];
        for ($index = $cursor->personIndex; $index < count($people); ++$index) {
            $person      = $people[$index];
            $connections = [];
            foreach ($this->people->connectionsOf($person->getDirectory()) as $connection) {
                if (!$board instanceof TaskBoard || $connection->getBoard()->getId() === $board->getId()) {
                    $connections[]                            = $connection;
                    $boards[$connection->getBoard()->getId()] = true;
                }
            }
            $step  = $this->walk($person, $user, $user, $connections, $batchSize, $index === $cursor->personIndex ? $cursor : new ReassignCursor($cursor->reassignId), true);
            $total = $total->plus($step->withCursor(null));
            if (!$step->isDone()) {
                return $total->withCursor(new ReassignCursor($cursor->reassignId, null, $index, (int) $step->cursor?->boardIndex, (string) $step->cursor?->lastItemId));
            }
        }
        $this->eventDispatcher?->dispatch(new SyncPeopleReprojectedEvent((string) SyncUser::id($user), array_keys($boards), $total, $trigger), TaskBoardEvents::SYNC_PEOPLE_REPROJECTED);

        return $total;
    }

    /**
     * Account erasure (run it **before** the user is deleted: tasks, comments and members cascade with the user): every
     * person mapped or suggested to `$user` becomes external (optionally anonymised) and what was written for them goes
     * back to `$actor` plus the person reference. No authorization check: a host erasure path calls it.
     */
    public function detachUser(object $user, object $actor, bool $anonymise = false, ?int $batchSize = null, ?ReassignCursor $cursor = null): ReassignReport
    {
        $cursor ??= new ReassignCursor(TaskSyncRun::newId());
        $total = new ReassignReport();
        // A person stays mapped until its content is moved, so a resumed call finds it first again.
        foreach ($this->people->linkedToUser($user) as $index => $person) {
            $step  = $this->walk($person, null, $actor, $this->people->connectionsOf($person->getDirectory()), $batchSize, $index === 0 ? $cursor : new ReassignCursor($cursor->reassignId));
            $total = $total->plus($step->withCursor(null));
            if (!$step->isDone()) {
                return $total->withCursor($step->cursor);
            }
            $this->directories->changeMapping($person, TaskSyncPersonMapping::External, null, $actor, SyncPersonMappedEvent::REASON_ERASED);
            if ($anonymise) {
                $this->directories->forget($person, SyncUser::id($actor));
            }
            $this->entityManager->flush();
        }

        return $total;
    }

    /**
     * The person on each connection from the cursor on, one batch at a time, under each board's lock.
     *
     * @param list<TaskBoardConnection> $connections
     * @param bool $projectOnly re-projection: a board the target cannot view is skipped
     */
    private function walk(TaskSyncPerson $person, ?object $target, object $fallback, array $connections, ?int $batchSize, ReassignCursor $cursor, bool $projectOnly = false): ReassignReport
    {
        $batchSize = max(1, $batchSize ?? $this->defaultBatchSize);
        $report    = new ReassignReport();
        $last      = $cursor->lastItemId;
        for ($index = $cursor->boardIndex; $index < count($connections); ++$index) {
            $connection = $connections[$index];
            $board      = $connection->getBoard();
            $projected  = $target !== null && $this->accessChecker->canViewBoard($target, $board) && $this->resolver->isCandidate($board, $target) ? $target : null;
            if ($target !== null && $projected === null) {
                $report = $report->plus(new ReassignReport(notProjected: 1));
                // Re-projection only ever adds rows where the user can view the board now; it never rewrites others.
                if ($projectOnly) {
                    $last = '';
                    continue;
                }
            }
            $now = $this->clock->now();
            if (!$this->store->acquireLock($connection, $cursor->reassignId, $now, $now->sub(new DateInterval(self::STALE_AFTER)))) {
                return $report->plus(new ReassignReport(busyBoards: 1))->withCursor(new ReassignCursor($cursor->reassignId, $cursor->previousUserId, $cursor->personIndex, $index, $last));
            }
            try {
                $step = $this->projection->apply($person, $projected, $fallback, $connection, $last, $batchSize);
            } finally {
                $this->store->releaseLock($connection->getId(), $cursor->reassignId);
            }
            $report = $report->plus($step['report']);
            if (!$step['done']) {
                return $report->withCursor(new ReassignCursor($cursor->reassignId, $cursor->previousUserId, $cursor->personIndex, $index, $step['lastItemId']));
            }
            $last = '';
        }

        return $report->withCursor(null);
    }
}
