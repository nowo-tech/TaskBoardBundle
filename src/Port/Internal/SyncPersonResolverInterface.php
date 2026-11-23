<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Port\Internal;

use Nowo\TaskBoardBundle\Entity\TaskBoard;
use Nowo\TaskBoardBundle\Sync\Hook\SyncPersonDirectoryInterface;
use Nowo\TaskBoardBundle\Sync\Hook\SyncPersonMatch;
use Nowo\TaskBoardBundle\Sync\People\SyncUser;
use Nowo\TaskBoardBundle\Sync\Provider\RemotePerson;
use Nowo\TaskBoardBundle\Sync\Provider\RemotePersonRef;

/**
 * Since 1.25 (`sync.people.resolver`): who remote people may be mapped to. Every answer is scoped to the board's
 * candidates (the host decides: project and organization members); the bundle never looks a user up elsewhere.
 *
 * Since 3.0.0 the bundle's own entity-typed seam (REQ-ARCH-001 rule 3): it was the host port `Nowo\TaskBoardBundle\Sync\Hook\SyncPersonResolverInterface`,
 * deprecated in 2.15.0 and removed in 3.0. Hosts implement the framework-free {@see SyncPersonDirectoryInterface};
 * the bundle asks it through its gate, and its own defaults implement this seam. A class outside the bundle that
 * implements this interface is refused when the container is built.
 *
 * @internal
 */
interface SyncPersonResolverInterface
{
    /** The tenant scope of the board's people directory, e.g. "project:<uuid>" or "org:<uuid>" ([a-z0-9:_-]{1,64}). */
    public function directoryScope(TaskBoard $board): string;

    /**
     * Candidates the board may map to, filtered by the query (name or e-mail).
     *
     * @return iterable<object>
     */
    public function candidates(TaskBoard $board, string $query = '', int $limit = 20): iterable;

    /** Whether the user is a candidate of the board (re-checked on every mapping and re-association). */
    public function isCandidate(TaskBoard $board, object $user): bool;

    /**
     * Exactly one candidate for the person's normalised e-mail (trimmed, NFC, lower-case), or null. Disabled or
     * anonymised accounts never match.
     *
     * `$normalizedEmail` is what the bundle passes (deliberate addition to the contract's two-argument call, which still
     * works): when it is null, the implementation derives it from `$person->email` with
     * {@see SyncUser::normalizeEmail()} and matches nothing without an e-mail.
     */
    public function autoMatch(TaskBoard $board, RemotePerson|RemotePersonRef $person, ?string $normalizedEmail = null): ?SyncPersonMatch;

    /** A candidate by id (the picker posts ids); null when unknown or not a candidate of the board. */
    public function candidateById(TaskBoard $board, string $userId): ?object;
}
