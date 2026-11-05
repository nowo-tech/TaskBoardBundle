<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Event;

use Nowo\TaskBoardBundle\Service\TaskBoardEraser;
use Symfony\Contracts\EventDispatcher\Event;

/**
 * Since 1.25 ({@see TaskBoardEvents::BOARD_EMPTIED} / {@see TaskBoardEvents::BOARD_DELETED}): a board was emptied or
 * deleted by {@see TaskBoardEraser}, dispatched once when the last slice finishes, for
 * the host's audit row. Ids and counts only.
 */
final class BoardErasedEvent extends Event
{
    public function __construct(
        public readonly string $boardId,
        public readonly int $tasksDeleted,
        public readonly int $columnsDeleted,
        public readonly bool $boardDeleted,
        public readonly ?string $actorId,
    ) {
    }
}
