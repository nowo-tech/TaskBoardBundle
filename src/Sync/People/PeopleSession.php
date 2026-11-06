<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Sync\People;

use Nowo\TaskBoardBundle\Entity\TaskBoard;
use Nowo\TaskBoardBundle\Security\TaskBoardResourceAccessChecker;
use Nowo\TaskBoardBundle\Sync\Entity\TaskSyncDirectory;
use Nowo\TaskBoardBundle\Sync\Entity\TaskSyncPerson;
use Nowo\TaskBoardBundle\Sync\Provider\RemotePerson;
use Nowo\TaskBoardBundle\Sync\Provider\RemotePersonRef;

use function in_array;

/**
 * Since 1.25: the people of one board for one page of a run (or one scan): remote refs → directory rows (each person
 * once), and who may be written as creator / member / author on the board (the projection rule: a mapped user who
 * cannot view the board is never projected — a mapping never grants access). Not a service: one per page.
 */
final class PeopleSession
{
    /** @var array<string, TaskSyncPerson> provider user id => person */
    private array $people = [];

    /** @var array<string, bool> user id => may view the board */
    private array $viewers = [];

    /** @var array<string, int> counters (people.created, people.autoMatched, …) */
    public array $stats = [];

    /** @var list<string> warnings (codes) */
    public array $warnings = [];

    public function __construct(
        public readonly TaskSyncDirectory $directory,
        public readonly TaskBoard $board,
        private readonly TaskSyncPeopleDirectoryService $directoryService,
        private readonly TaskBoardResourceAccessChecker $accessChecker,
    ) {
    }

    /** The directory row of a remote person (created or refreshed once per session). */
    public function person(RemotePerson|RemotePersonRef $remote, int $kind): TaskSyncPerson
    {
        $known = $this->people[$remote->providerUserId] ?? null;
        if ($known instanceof TaskSyncPerson) {
            if (!$known->hasSeenKind($kind)) {
                $known->refresh($known->getName(), null, null, null, $kind, $known->getLastSeenAt());
            }

            return $known;
        }

        return $this->people[$remote->providerUserId] = $this->directoryService->upsertPerson($this, $remote, $kind); // @igor-ignore - one object per page, never a service
    }

    /** The user to write for the person on this board: mapped (auto / manual) and allowed to view the board; else null. */
    public function projected(?TaskSyncPerson $person): ?object
    {
        $user = $person?->getMappedUser();
        if ($user === null) {
            return null;
        }
        $id = SyncUser::id($user) ?? '';

        // Since 1.28.0: attribution only (a suspended member): never written as creator, author or member.
        return ($this->viewers[$id] ??= $this->accessChecker->canViewBoard($user, $this->board) && !$this->directoryService->isAttributionOnly($this->board, $user)) ? $user : null; // @igor-ignore - one object per page, never a service
    }

    /** Adds to a counter. */
    public function count(string $name, int $by = 1): void
    {
        $this->stats[$name] = ($this->stats[$name] ?? 0) + $by;
    }

    /** Records a warning code once. */
    public function warn(string $code): void
    {
        if (!in_array($code, $this->warnings, true)) {
            $this->warnings[] = $code;
        }
    }
}
