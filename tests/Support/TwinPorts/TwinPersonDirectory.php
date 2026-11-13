<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Tests\Support\TwinPorts;

use Nowo\TaskBoardBundle\Sync\Hook\SyncPersonDirectoryInterface;
use Nowo\TaskBoardBundle\Sync\Hook\SyncPersonMatch;
use Nowo\TaskBoardBundle\Sync\Hook\SyncPersonResolverInterface;
use Nowo\TaskBoardBundle\Sync\Provider\RemotePerson;
use Nowo\TaskBoardBundle\Sync\Provider\RemotePersonRef;
use Nowo\TaskBoardBundle\ValueObject\TaskBoardSnapshot;

class TwinPersonDirectory implements SyncPersonDirectoryInterface
{
    public function __construct(protected readonly SyncPersonResolverInterface $legacy, protected readonly TwinLookup $lookup)
    {
    }

    public function directoryScope(TaskBoardSnapshot $board): string
    {
        return $this->legacy->directoryScope($this->lookup->board($board->id));
    }

    public function candidates(TaskBoardSnapshot $board, string $query = '', int $limit = 20): iterable
    {
        return $this->legacy->candidates($this->lookup->board($board->id), $query, $limit);
    }

    public function isCandidate(TaskBoardSnapshot $board, object $user): bool
    {
        return $this->legacy->isCandidate($this->lookup->board($board->id), $user);
    }

    public function autoMatch(TaskBoardSnapshot $board, RemotePerson|RemotePersonRef $person, ?string $normalizedEmail = null): ?SyncPersonMatch
    {
        return $this->legacy->autoMatch($this->lookup->board($board->id), $person, $normalizedEmail);
    }

    public function candidateById(TaskBoardSnapshot $board, string $userId): ?object
    {
        return $this->legacy->candidateById($this->lookup->board($board->id), $userId);
    }
}
