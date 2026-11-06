<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Sync\Event;

use Symfony\Contracts\EventDispatcher\Event;

/**
 * Since 1.28.0: the first import's `unmatchedColumns: delete` ran (`TaskBoardEvents::SYNC_UNMATCHED_COLUMNS_RESOLVED`),
 * for a host's audit trail.
 */
final class UnmatchedColumnsResolvedEvent extends Event
{
    /**
     * @param array<string, string> $deleted column id => name
     * @param list<string> $kept names of the unmatched columns that stayed
     */
    public function __construct(
        public readonly string $boardId,
        public readonly string $connectionId,
        public readonly string $runId,
        public readonly array $deleted,
        public readonly array $kept,
    ) {
    }
}
