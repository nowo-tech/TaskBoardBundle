<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Event;

/**
 * Since 2.10.0: {@see TaskBoardEvents::COLUMNS_REORDERED} — a board's columns have a new order (`columnIds`, first to last).
 * Not dispatched when the order is the same.
 */
final class ColumnsReorderedEvent extends AbstractColumnEvent
{
    /**
     * @param list<string> $columnIds
     */
    public function __construct(
        string $boardId,
        ?string $tenantKey,
        public readonly array $columnIds,
        ?string $actorId,
        ?string $cause = null,
        ?object $actor = null,
    ) {
        parent::__construct($boardId, $tenantKey, $actorId, $cause, $actor);
    }
}
