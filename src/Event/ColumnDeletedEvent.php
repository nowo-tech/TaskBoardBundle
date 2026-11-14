<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Event;

/**
 * Since 2.10.0: {@see TaskBoardEvents::COLUMN_DELETED} — a column was deleted; its tasks (`movedTaskIds`) moved to
 * `targetColumnId`, the first remaining column. The column's id no longer exists when listeners run: a host pauses or
 * updates what names it (an automation rule, a saved filter).
 */
final class ColumnDeletedEvent extends AbstractColumnEvent
{
    /**
     * @param list<string> $movedTaskIds
     */
    public function __construct(
        string $boardId,
        ?string $tenantKey,
        public readonly string $columnId,
        public readonly string $name,
        public readonly string $targetColumnId,
        public readonly array $movedTaskIds,
        ?string $actorId,
        ?string $cause = null,
        ?object $actor = null,
    ) {
        parent::__construct($boardId, $tenantKey, $actorId, $cause, $actor);
    }
}
