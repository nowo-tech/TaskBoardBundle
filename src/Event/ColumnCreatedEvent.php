<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Event;

/**
 * Since 2.10.0: {@see TaskBoardEvents::COLUMN_CREATED} — a column was added to a board (the board, the API, a sync or an
 * import).
 */
final class ColumnCreatedEvent extends AbstractColumnEvent
{
    public function __construct(
        string $boardId,
        ?string $tenantKey,
        public readonly string $columnId,
        public readonly string $name,
        public readonly int $position,
        ?string $actorId,
        ?string $cause = null,
        ?object $actor = null,
    ) {
        parent::__construct($boardId, $tenantKey, $actorId, $cause, $actor);
    }
}
