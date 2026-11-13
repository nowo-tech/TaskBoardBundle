<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Port;

use Nowo\TaskBoardBundle\DependencyInjection\Compiler\TaskBoardPortsPass;
use Nowo\TaskBoardBundle\Entity\TaskBoard;
use Nowo\TaskBoardBundle\Sync\Hook\AttributionOnlyPolicyInterface;
use Nowo\TaskBoardBundle\Sync\Hook\AttributionOnlyResolverInterface;
use Nowo\TaskBoardBundle\Sync\Hook\SyncPersonDirectoryInterface;
use Nowo\TaskBoardBundle\Sync\Hook\SyncPersonMatch;
use Nowo\TaskBoardBundle\Sync\Hook\SyncPersonResolverInterface;
use Nowo\TaskBoardBundle\Sync\Provider\RemotePerson;
use Nowo\TaskBoardBundle\Sync\Provider\RemotePersonRef;

/**
 * Since 2.15.0: asks a host {@see SyncPersonDirectoryInterface} where the bundle asks the deprecated
 * {@see SyncPersonResolverInterface}. Exceptions propagate. Stateless.
 *
 * @internal built by {@see Ports} / {@see TaskBoardPortsPass}
 */
final readonly class SyncPersonDirectoryGate implements SyncPersonResolverInterface, AttributionOnlyResolverInterface
{
    public function __construct(private SyncPersonDirectoryInterface $directory)
    {
    }

    public function directoryScope(TaskBoard $board): string
    {
        return $this->directory->directoryScope(Snapshots::board($board));
    }

    public function candidates(TaskBoard $board, string $query = '', int $limit = 20): iterable
    {
        return $this->directory->candidates(Snapshots::board($board), $query, $limit);
    }

    public function isCandidate(TaskBoard $board, object $user): bool
    {
        return $this->directory->isCandidate(Snapshots::board($board), $user);
    }

    public function autoMatch(TaskBoard $board, RemotePerson|RemotePersonRef $person, ?string $normalizedEmail = null): ?SyncPersonMatch
    {
        return $this->directory->autoMatch(Snapshots::board($board), $person, $normalizedEmail);
    }

    public function candidateById(TaskBoard $board, string $userId): ?object
    {
        return $this->directory->candidateById(Snapshots::board($board), $userId);
    }

    /**
     * The directory's own attribution-only answer ({@see AttributionOnlyPolicyInterface} or the deprecated
     * {@see AttributionOnlyResolverInterface}); false when it implements neither, which is what the bundle concludes for a
     * resolver without the optional interface.
     */
    public function isAttributionOnly(TaskBoard $board, object $user): bool
    {
        return Ports::isAttributionOnly($this->directory, $board, $user);
    }
}
