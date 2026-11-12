<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Sync\Hook;

use Nowo\TaskBoardBundle\Sync\Provider\RemotePerson;
use Nowo\TaskBoardBundle\Sync\Provider\RemotePersonRef;
use Nowo\TaskBoardBundle\ValueObject\TaskBoardSnapshot;

/**
 * Since 2.15.0 (REQ-ARCH-001 rule 1): the framework-free twin of {@see SyncPersonResolverInterface}: who remote people may
 * be mapped to, with the board as a {@see TaskBoardSnapshot}. Same contract as the deprecated interface; implement
 * {@see AttributionOnlyPolicyInterface} too for attribution-only candidates.
 *
 * Set `sync.people.resolver` to a service implementing it. A service that also implements the deprecated interface is
 * asked through that one until 3.0.
 */
interface SyncPersonDirectoryInterface
{
    /** The tenant scope of the board's people directory, e.g. "project:<uuid>" ([a-z0-9:_-]{1,64}). */
    public function directoryScope(TaskBoardSnapshot $board): string;

    /**
     * Candidates the board may map to, filtered by the query (name or e-mail).
     *
     * @return iterable<object>
     */
    public function candidates(TaskBoardSnapshot $board, string $query = '', int $limit = 20): iterable;

    /** Whether the user is a candidate of the board (re-checked on every mapping and re-association). */
    public function isCandidate(TaskBoardSnapshot $board, object $user): bool;

    /** Exactly one candidate for the person's normalised e-mail, or null (see the deprecated interface). */
    public function autoMatch(TaskBoardSnapshot $board, RemotePerson|RemotePersonRef $person, ?string $normalizedEmail = null): ?SyncPersonMatch;

    /** A candidate by id (the picker posts ids); null when unknown or not a candidate of the board. */
    public function candidateById(TaskBoardSnapshot $board, string $userId): ?object;
}
