<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Sync\People;

use DateInterval;
use Doctrine\ORM\EntityManagerInterface;
use Nowo\TaskBoardBundle\Entity\TaskBoard;
use Nowo\TaskBoardBundle\Security\TaskBoardResourceAccessChecker;
use Nowo\TaskBoardBundle\Sync\Entity\TaskSyncDirectory;
use Nowo\TaskBoardBundle\Sync\Entity\TaskSyncPerson;
use Nowo\TaskBoardBundle\Sync\Hook\SyncPersonMatch;
use Nowo\TaskBoardBundle\Sync\Hook\SyncPersonResolverInterface;
use Nowo\TaskBoardBundle\Sync\Provider\PeopleProviderInterface;
use Nowo\TaskBoardBundle\Sync\Provider\ProviderCredential;
use Nowo\TaskBoardBundle\Sync\Provider\RemoteLocation;
use Nowo\TaskBoardBundle\Sync\Provider\RemoteLocationKind;
use Nowo\TaskBoardBundle\Sync\Provider\RemotePerson;
use Nowo\TaskBoardBundle\Sync\Provider\RemotePersonRef;
use Nowo\TaskBoardBundle\Sync\Provider\RemoteSource;
use Nowo\TaskBoardBundle\Sync\Provider\RemoteTask;
use Nowo\TaskBoardBundle\Sync\Provider\SourceProviderInterface;
use Nowo\TaskBoardBundle\Sync\Provider\TaskProviderInterface;
use Nowo\TaskBoardBundle\Sync\Provider\TimeAccess;
use Nowo\TaskBoardBundle\Sync\Provider\TimeAccessMode;
use Nowo\TaskBoardBundle\Sync\Provider\TimeEntryProviderInterface;
use Nowo\TaskBoardBundle\Sync\Provider\TimeEntryQuery;
use Psr\Clock\ClockInterface;
use Symfony\Component\Clock\NativeClock;

use function array_chunk;
use function array_keys;
use function array_map;
use function array_search;
use function array_slice;
use function max;
use function min;

/**
 * Since 1.25: the dialog's bounded people pre-scan (§2.2): workspace members, the first task page(s), the comments of
 * the first tasks and, with an admin token, one 30-day time window. Read-only on the provider; no task is written.
 * With `$persist` the people are upserted (and auto-matched) into the board's draft directory; otherwise the match is
 * only computed. Authorisation: `canManageBoardConnection` on the board, else {@see PeopleMappingDeniedException}.
 */
final readonly class TaskSyncPeopleScanner
{
    /** The most requests one scan spends (members + tasks + comments + time). */
    public const MAX_CALLS = 15;

    private const KINDS = [
        TaskSyncPerson::KIND_MEMBER     => 'member',
        TaskSyncPerson::KIND_CREATOR    => 'creator',
        TaskSyncPerson::KIND_ASSIGNEE   => 'assignee',
        TaskSyncPerson::KIND_WATCHER    => 'watcher',
        TaskSyncPerson::KIND_CHECKLIST  => 'checklist',
        TaskSyncPerson::KIND_COMMENT    => 'comment',
        TaskSyncPerson::KIND_TIME_ENTRY => 'time',
    ];

    public function __construct(
        private EntityManagerInterface $entityManager,
        private TaskSyncPeopleDirectoryService $directories,
        private TaskSyncPersonRepository $people,
        private SyncPersonResolverInterface $resolver,
        private TaskBoardResourceAccessChecker $accessChecker,
        private ClockInterface $clock = new NativeClock(),
        private int $maxTaskPages = 1,
        private int $maxCommentTasks = 10,
        private bool $timeWindow = true,
        private int $maxAssigneesPerCall = 25,
    ) {
    }

    /**
     * @throws PeopleMappingDeniedException
     */
    public function scan(TaskBoard $board, TaskProviderInterface $provider, ProviderCredential $credential, string $workspaceId, RemoteLocation $location, object $actor, bool $persist = false): PeopleScanResult
    {
        if (!$this->accessChecker->canManageBoardConnection($actor, $board)) {
            throw new PeopleMappingDeniedException();
        }
        if (!$provider instanceof PeopleProviderInterface) {
            return new PeopleScanResult([], null, 0);
        }
        /** @var array<string, array{0: RemotePerson|RemotePersonRef, 1: array<string, int>}> $seen */
        $seen = [];
        $add  = static function (RemotePerson|RemotePersonRef|null $person, int $kind) use (&$seen): void {
            if ($person === null) {
                return;
            }
            $known = $seen[$person->providerUserId] ?? null;
            // A member row (with its role) wins over a ref of the same person.
            $remote                        = $known !== null && $known[0] instanceof RemotePerson ? $known[0] : $person;
            $counts                        = $known[1] ?? [];
            $counts[self::KINDS[$kind]]    = ($counts[self::KINDS[$kind]] ?? 0) + 1;
            $seen[$person->providerUserId] = [$remote, $counts];
        };
        $calls = 1;
        foreach ($provider->fetchPeople($credential, $workspaceId)->people as $member) {
            $add($member, TaskSyncPerson::KIND_MEMBER);
        }
        $calls += $this->scanTasks($provider, $credential, $location, $add);
        $access = null;
        if ($calls < self::MAX_CALLS) {
            $access = $provider->timeAccess($credential, $workspaceId);
            $calls += 2;
        }
        if ($access instanceof TimeAccess && $this->timeWindow && $access->mode === TimeAccessMode::Admin && $provider instanceof TimeEntryProviderInterface && $calls < self::MAX_CALLS) {
            $now = $this->clock->now();
            // Numeric provider ids become int keys: back to strings for the query.
            $ids = array_chunk(array_map(strval(...), array_keys($seen)), max(1, $this->maxAssigneesPerCall))[0] ?? null;
            foreach ($provider->fetchTimeEntries($credential, new TimeEntryQuery($workspaceId, $location, $now->sub(new DateInterval('P30D')), $now, $ids)) as $entry) {
                $add($entry->person, TaskSyncPerson::KIND_TIME_ENTRY);
            }
            ++$calls;
        }

        return $this->result($board, $provider->id(), $workspaceId, $seen, $access, $calls, $persist);
    }

    /**
     * The first task page(s) and the comments of the first tasks; returns the calls spent.
     *
     * @param callable(RemotePerson|RemotePersonRef|null, int): void $add
     */
    private function scanTasks(TaskProviderInterface $provider, ProviderCredential $credential, RemoteLocation $location, callable $add): int
    {
        $calls  = 0;
        $cursor = null;
        $source = null;
        if ($location->kind !== RemoteLocationKind::List && $provider instanceof SourceProviderInterface) {
            $source = $provider->findSource($credential, $location->containerId());
            ++$calls;
            if (!$source instanceof RemoteSource) {
                return $calls;
            }
        }
        /** @var list<RemoteTask> $tasks */
        $tasks = [];
        for ($page = 0; $page < max(1, $this->maxTaskPages); ++$page) {
            $answer = $source instanceof RemoteSource && $provider instanceof SourceProviderInterface
                ? $provider->fetchSourceTasks($credential, $source, null, $cursor)
                : $provider->fetchTasks($credential, $location->id, null, $cursor);
            ++$calls;
            foreach ($answer->tasks as $remote) {
                $tasks[] = $remote;
                $add($remote->task->creator, TaskSyncPerson::KIND_CREATOR);
                foreach ($remote->task->assigneeRefs as $ref) {
                    $add($ref, TaskSyncPerson::KIND_ASSIGNEE);
                }
                foreach ($remote->watchers as $ref) {
                    $add($ref, TaskSyncPerson::KIND_WATCHER);
                }
                foreach ($remote->checklists as $checklist) {
                    foreach ($checklist->items as $item) {
                        $add($item->assignee, TaskSyncPerson::KIND_CHECKLIST);
                    }
                }
            }
            $cursor = $answer->nextCursor;
            if ($answer->lastPage || $cursor === null) {
                break;
            }
        }
        $budget = min($this->maxCommentTasks, self::MAX_CALLS - 4 - $calls);
        foreach (array_slice($tasks, 0, max(0, $budget)) as $remote) {
            if ($remote->task->externalId === null) {
                continue;
            }
            foreach ($provider->fetchComments($credential, $remote->task->externalId) as $comment) {
                $add($comment->author, TaskSyncPerson::KIND_COMMENT);
            }
            ++$calls;
        }

        return $calls;
    }

    /**
     * @param array<string, array{0: RemotePerson|RemotePersonRef, 1: array<string, int>}> $seen
     */
    private function result(TaskBoard $board, string $provider, string $workspaceId, array $seen, ?TimeAccess $access, int $calls, bool $persist): PeopleScanResult
    {
        $directory = $persist ? $this->directories->directoryForDraft($board, $provider, $workspaceId) : $this->people->findDirectory($provider, $this->resolver->directoryScope($board), $workspaceId);
        $session   = $persist && $directory instanceof TaskSyncDirectory ? $this->directories->session($directory, $board) : null;
        $people    = [];
        foreach ($seen as [$remote, $counts]) {
            $person = null;
            if ($session instanceof PeopleSession) {
                foreach ($counts as $kindName => $n) {
                    $person = $session->person($remote, (int) array_search($kindName, self::KINDS, true));
                }
            } elseif ($directory instanceof TaskSyncDirectory) {
                $person = $this->people->findOneByProviderUserId($directory, $remote->providerUserId);
            }
            [$match, $user] = $this->match($board, $remote, $person);
            $people[]       = new ScannedPerson(
                providerUserId: $remote->providerUserId,
                name: $remote->name,
                email: $remote->email,
                role: $remote instanceof RemotePerson ? $remote->role?->value : null,
                counts: $counts,
                personId: $person?->getId(),
                mapping: $person?->getMapping()->value,
                match: $match,
                matchedUserId: SyncUser::id($user),
                boardAccess: $user === null ? null : $this->accessChecker->canViewBoard($user, $board),
            );
        }
        if ($session instanceof PeopleSession) {
            $this->entityManager->flush();
        }

        return new PeopleScanResult($people, $access, $calls, $directory?->getId());
    }

    /**
     * What an automatic match does (or did) for the person, and the user it points at (mapped, suggested or matched).
     *
     * @return array{0: string, 1: object|null}
     */
    private function match(TaskBoard $board, RemotePerson|RemotePersonRef $remote, ?TaskSyncPerson $person): array
    {
        if ($person instanceof TaskSyncPerson) {
            $user = $person->getMappedUser() ?? $person->getSuggestedUser();

            return [$person->getSuggestedUser() !== null ? ScannedPerson::MATCH_SUGGESTED : ($person->getMappedUser() !== null ? ScannedPerson::MATCH_AUTO : ScannedPerson::MATCH_NONE), $user];
        }
        $email = SyncUser::normalizeEmail($remote->email);
        $found = $email === null ? null : $this->resolver->autoMatch($board, $remote, $email);
        if (!$found instanceof SyncPersonMatch) {
            return [ScannedPerson::MATCH_NONE, null];
        }

        return [$found->verified ? ScannedPerson::MATCH_AUTO : ScannedPerson::MATCH_SUGGESTED, $found->user];
    }
}
