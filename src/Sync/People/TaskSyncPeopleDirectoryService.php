<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Sync\People;

use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Nowo\TaskBoardBundle\Entity\TaskBoard;
use Nowo\TaskBoardBundle\Event\TaskBoardEvents;
use Nowo\TaskBoardBundle\Security\TaskBoardResourceAccessChecker;
use Nowo\TaskBoardBundle\Support\Text;
use Nowo\TaskBoardBundle\Sync\Entity\TaskBoardConnection;
use Nowo\TaskBoardBundle\Sync\Entity\TaskSyncDirectory;
use Nowo\TaskBoardBundle\Sync\Entity\TaskSyncPerson;
use Nowo\TaskBoardBundle\Sync\Entity\TaskSyncPersonMapping;
use Nowo\TaskBoardBundle\Sync\Event\SyncPeopleReviewedEvent;
use Nowo\TaskBoardBundle\Sync\Event\SyncPersonAnonymisedEvent;
use Nowo\TaskBoardBundle\Sync\Event\SyncPersonMappedEvent;
use Nowo\TaskBoardBundle\Sync\Hook\SyncPersonMatch;
use Nowo\TaskBoardBundle\Sync\Hook\SyncPersonResolverInterface;
use Nowo\TaskBoardBundle\Sync\Provider\PeopleProviderInterface;
use Nowo\TaskBoardBundle\Sync\Provider\ProviderCredential;
use Nowo\TaskBoardBundle\Sync\Provider\RemotePerson;
use Nowo\TaskBoardBundle\Sync\Provider\RemotePersonRef;
use Nowo\TaskBoardBundle\Sync\Provider\TaskProviderInterface;
use Psr\Clock\ClockInterface;
use Symfony\Component\Clock\NativeClock;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;
use Throwable;

use function preg_match;

/**
 * Since 1.25: the people directory of a scope (§4 of the contract): find or create it, refresh it from the provider
 * and from the refs a run sees, the automatic e-mail match, the human mapping decisions, "Forget this person", and the
 * prune when the last connection goes. Who may manage it: `canManageBoardConnection` on every board using it.
 */
final readonly class TaskSyncPeopleDirectoryService
{
    /** The most member pages one refresh reads. */
    public const MAX_PEOPLE_PAGES = 50;

    public function __construct(
        private EntityManagerInterface $entityManager,
        private TaskSyncPersonRepository $people,
        private SyncPersonResolverInterface $resolver,
        private TaskBoardResourceAccessChecker $accessChecker,
        private ?EventDispatcherInterface $eventDispatcher = null,
        private ClockInterface $clock = new NativeClock(),
    ) {
    }

    /**
     * The directory of a board's connection; attaches one (workspace looked up once) when missing. Null when the
     * provider has no people or the workspace cannot be found.
     */
    public function directoryFor(TaskBoardConnection $connection, TaskProviderInterface $provider, ProviderCredential $credential): ?TaskSyncDirectory
    {
        $directory = $connection->getDirectory();
        if ($directory instanceof TaskSyncDirectory) {
            return $directory;
        }
        if (!$provider instanceof PeopleProviderInterface || !$provider->capabilities()->people) {
            return null;
        }
        $workspaceId = $provider->workspaceOf($credential, $connection->getLocation()->containerId());
        if ($workspaceId === null) {
            return null;
        }
        $directory = $this->directoryForDraft($connection->getBoard(), $provider->id(), $workspaceId);
        $connection->setDirectory($directory);
        $this->entityManager->flush();

        return $directory;
    }

    /** The directory of a scope and workspace (created when missing), e.g. for the dialog before the link exists. */
    public function directoryForDraft(TaskBoard $board, string $provider, string $workspaceId): TaskSyncDirectory
    {
        $scope = $this->resolver->directoryScope($board);
        if (preg_match('/^[a-z0-9:_-]{1,64}$/D', $scope) !== 1) {
            throw new InvalidMappingException('invalid_scope');
        }
        $workspaceId = Text::cut($workspaceId, 64);
        $directory   = $this->people->findDirectory($provider, $scope, $workspaceId);
        if (!$directory instanceof TaskSyncDirectory) {
            $directory = new TaskSyncDirectory($provider, $scope, $workspaceId);
            $this->entityManager->persist($directory);
            $this->entityManager->flush();
        }

        return $directory;
    }

    /** A session (one page of a run, one scan) on the board. */
    public function session(TaskSyncDirectory $directory, TaskBoard $board): PeopleSession
    {
        return new PeopleSession($directory, $board, $this, $this->accessChecker);
    }

    /**
     * Upserts the workspace members (page by page, bounded); returns the session's counters.
     */
    public function refreshMembers(PeopleSession $session, PeopleProviderInterface $provider, ProviderCredential $credential): void
    {
        $cursor = null;
        for ($page = 0; $page < self::MAX_PEOPLE_PAGES; ++$page) {
            $answer = $provider->fetchPeople($credential, $session->directory->getWorkspaceId(), $cursor);
            foreach ($answer->people as $person) {
                $session->person($person, TaskSyncPerson::KIND_MEMBER);
            }
            $cursor = $answer->nextCursor;
            if ($cursor === null) {
                break;
            }
        }
        $this->entityManager->flush();
    }

    /**
     * The directory row of a remote person: created or refreshed, then matched automatically when no human decided
     * (§4.2 / §4.3 of the contract). Used through {@see PeopleSession::person()}.
     *
     * @internal
     */
    public function upsertPerson(PeopleSession $session, RemotePerson|RemotePersonRef $remote, int $kind): TaskSyncPerson
    {
        $now        = $this->clock->now();
        $directory  = $session->directory;
        $normalized = SyncUser::normalizeEmail($remote->email);
        $role       = $remote instanceof RemotePerson ? $remote->role?->value : null;
        $name       = Text::cut($remote->name, 120);
        $person     = $this->people->findOneByProviderUserId($directory, $remote->providerUserId);
        $new        = !$person instanceof TaskSyncPerson;
        $session->count('people.seen');
        if (!$person instanceof TaskSyncPerson) {
            $person = new TaskSyncPerson($directory, $remote->providerUserId, $name, $now);
            $this->entityManager->persist($person);
            $session->count('people.created');
        }
        if ($person->refresh($name, $normalized === null ? null : Text::cut((string) $remote->email, 254), $normalized === null ? null : Text::cut($normalized, 254), $role, $kind, $now)) {
            $session->count('people.changedEmail');
        }
        if ($person->isAnonymised() || $person->getMapping()->isHumanDecision()) {
            return $person;
        }

        if ($person->getMapping() === TaskSyncPersonMapping::Auto) {
            // An automatic mapping whose e-mail changed, or whose user is gone, becomes unmatched; nothing moves.
            if ($person->getEmailChangedAt() instanceof DateTimeImmutable || $person->getMappedUser() === null) {
                $this->changeMapping($person, TaskSyncPersonMapping::Unmatched, null, null, SyncPersonMappedEvent::REASON_AUTO_UNMATCHED);
                $session->count('people.autoUnmatched');
                $session->warn('person_auto_unmatched');
            }

            return $person;
        }

        // Unmatched or suggested, never changed its e-mail: the automatic match (verified e-mails only).
        if ($normalized !== null && !$person->getEmailChangedAt() instanceof DateTimeImmutable) {
            $match = $this->resolver->autoMatch($session->board, $remote, $normalized);
            if ($match instanceof SyncPersonMatch) {
                $mapping = $match->verified ? TaskSyncPersonMapping::Auto : TaskSyncPersonMapping::Suggested;
                if ($mapping !== $person->getMapping() || !SyncUser::same($match->user, $person->getSuggestedUser())) {
                    $this->changeMapping($person, $mapping, $match->user, null, $match->verified ? SyncPersonMappedEvent::REASON_AUTO : SyncPersonMappedEvent::REASON_SUGGESTED);
                    $session->count($match->verified ? 'people.autoMatched' : 'people.suggested');
                }

                return $person;
            }
        }
        if ($new) {
            $session->count('people.unmatchedNew');
            $session->warn('people_unmatched_new');
        }

        return $person;
    }

    /**
     * Human decisions of the People step / page. Nothing already imported moves (call the reassigner for that).
     *
     * @param list<PersonMappingDecision> $decisions
     *
     * @throws PeopleMappingDeniedException
     * @throws InvalidMappingException
     *
     * @return list<TaskSyncPerson> the people whose mapping changed
     */
    public function applyMapping(TaskSyncDirectory $directory, array $decisions, object $actor, ?TaskBoard $draftBoard = null): array
    {
        $boards  = $this->assertCanManage($directory, $actor, $draftBoard);
        $changed = [];
        foreach ($decisions as $decision) {
            $person = $this->people->findPerson($decision->personId);
            if (!$person instanceof TaskSyncPerson || $person->getDirectory()->getId() !== $directory->getId()) {
                throw new InvalidMappingException('unknown_person');
            }
            if ($person->isAnonymised() && $decision->kind !== PersonMappingDecision::EXTERNAL) {
                throw new InvalidMappingException('anonymised');
            }
            [$mapping, $user, $reason] = match ($decision->kind) {
                PersonMappingDecision::MANUAL                                             => [TaskSyncPersonMapping::Manual, $this->candidate($boards, (string) $decision->userId), SyncPersonMappedEvent::REASON_MANUAL],
                PersonMappingDecision::EXTERNAL                                           => [TaskSyncPersonMapping::External, null, SyncPersonMappedEvent::REASON_EXTERNAL],
                PersonMappingDecision::CONFIRM, PersonMappingDecision::CONFIRM_SUGGESTION => [TaskSyncPersonMapping::Manual, $this->confirmable($person, $boards), SyncPersonMappedEvent::REASON_CONFIRM],
                default                                                                   => throw new InvalidMappingException('invalid_kind'),
            };
            if ($person->getMapping() === $mapping && ($user === null || SyncUser::same($person->getMappedUser(), $user))) {
                continue;
            }
            $this->changeMapping($person, $mapping, $user, $actor, $reason);
            $changed[] = $person;
        }
        $this->entityManager->flush();

        return $changed;
    }

    /** People nobody decided yet (unmatched or suggested, not anonymised): the People step is still due. */
    public function unreviewedCount(TaskSyncDirectory $directory): int
    {
        return $this->people->unreviewedCount($directory);
    }

    /** The People step was saved: the first import may run (`SyncOptions::requirePeopleMapping`). */
    public function markReviewed(TaskSyncDirectory $directory, object $actor, ?TaskBoard $draftBoard = null): void
    {
        $this->assertCanManage($directory, $actor, $draftBoard);
        $directory->markReviewed($this->managed($actor), $this->clock->now());
        $this->entityManager->flush();
        $this->eventDispatcher?->dispatch(new SyncPeopleReviewedEvent($directory->getId(), SyncUser::id($actor), $this->people->mappingCounts($directory)), TaskBoardEvents::SYNC_PEOPLE_REVIEWED);
    }

    /** "Forget this person": name and e-mail cleared; references and the mapping stay. */
    public function anonymise(TaskSyncPerson $person, object $actor): void
    {
        $this->assertCanManage($person->getDirectory(), $actor, null);
        $this->forget($person, SyncUser::id($actor));
        $this->entityManager->flush();
    }

    /**
     * Anonymises a person without an authorization check (erasure paths).
     *
     * @internal
     */
    public function forget(TaskSyncPerson $person, ?string $actorId): void
    {
        if ($person->isAnonymised()) {
            return;
        }
        $person->anonymise($this->clock->now());
        $this->eventDispatcher?->dispatch(new SyncPersonAnonymisedEvent($person->getDirectory()->getId(), $person->getId(), $actorId), TaskBoardEvents::SYNC_PERSON_ANONYMISED);
    }

    /**
     * After the last connection of a directory is gone: deletes the people nothing references, anonymises the rest,
     * and the directory itself when it is empty. Returns the number of people deleted.
     */
    public function pruneDirectory(TaskSyncDirectory $directory): int
    {
        if ($this->people->connectionsOf($directory) !== []) {
            return 0;
        }
        $deleted = 0;
        $kept    = 0;
        foreach ($this->people->forDirectory($directory, new PersonFilter(), 100_000) as $person) {
            if ($this->people->isReferenced($person)) {
                $this->forget($person, null);
                ++$kept;
                continue;
            }
            $this->entityManager->remove($person);
            ++$deleted;
        }
        if ($kept === 0) {
            $this->entityManager->remove($directory);
        }
        $this->entityManager->flush();

        return $deleted;
    }

    /**
     * The boards of the directory the actor may manage — all of them, else the denial.
     *
     * @throws PeopleMappingDeniedException
     *
     * @return list<TaskBoard>
     */
    public function assertCanManage(TaskSyncDirectory $directory, object $actor, ?TaskBoard $draftBoard): array
    {
        $boards = [];
        foreach ($this->people->connectionsOf($directory) as $connection) {
            $boards[] = $connection->getBoard();
        }
        if ($draftBoard instanceof TaskBoard && $this->resolver->directoryScope($draftBoard) === $directory->getScopeKey()) {
            $boards[] = $draftBoard;
        }
        if ($boards === []) {
            throw new PeopleMappingDeniedException();
        }
        foreach ($boards as $board) {
            if (!$this->accessChecker->canManageBoardConnection($actor, $board)) {
                throw new PeopleMappingDeniedException();
            }
        }

        return $boards;
    }

    /**
     * Changes a mapping and dispatches the audit event.
     *
     * @internal
     */
    public function changeMapping(TaskSyncPerson $person, TaskSyncPersonMapping $mapping, ?object $user, ?object $actor, string $reason): void
    {
        $previous     = $person->getMapping();
        $previousUser = SyncUser::id($person->getMappedUser() ?? $person->getSuggestedUser());
        $person->map($mapping, $user, $actor === null ? null : $this->managed($actor), $this->clock->now());
        $this->eventDispatcher?->dispatch(new SyncPersonMappedEvent(
            $person->getDirectory()->getId(),
            $person->getId(),
            $previous->value,
            $previousUser,
            $mapping->value,
            SyncUser::id($user),
            SyncUser::id($actor),
            $reason,
        ), TaskBoardEvents::SYNC_PERSON_MAPPED);
    }

    /**
     * A candidate of every board.
     *
     * @param list<TaskBoard> $boards
     */
    public function candidate(array $boards, string $userId): object
    {
        $user = null;
        foreach ($boards as $board) {
            $user = $this->resolver->candidateById($board, $userId);
            if ($user === null || !$this->resolver->isCandidate($board, $user)) {
                throw new InvalidMappingException('not_candidate');
            }
        }
        if ($user === null) {
            throw new InvalidMappingException('not_candidate');
        }

        return $user;
    }

    /**
     * @param list<TaskBoard> $boards
     */
    private function confirmable(TaskSyncPerson $person, array $boards): object
    {
        $user = $person->getSuggestedUser();
        if ($user === null) {
            throw new InvalidMappingException('no_suggestion');
        }
        foreach ($boards as $board) {
            if (!$this->resolver->isCandidate($board, $user)) {
                throw new InvalidMappingException('not_candidate');
            }
        }

        return $user;
    }

    /** A user reference Doctrine can store (the same object when it is not a mapped entity). */
    private function managed(object $user): object
    {
        try {
            $metadata = $this->entityManager->getClassMetadata($user::class);
            $id       = $metadata->getIdentifierValues($user);

            return $id === [] ? $user : ($this->entityManager->getReference($metadata->getName(), $id) ?? $user);
        } catch (Throwable) {
            return $user;
        }
    }
}
