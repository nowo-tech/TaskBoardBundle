<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Event;

use Nowo\TaskBoardBundle\Entity\TaskBoard;
use Symfony\Contracts\EventDispatcher\Event;

/**
 * Since 1.30.0 (spec 211): a board was created and saved (`TaskBoardEvents::BOARD_CREATED`), by any path. The tenant is on the
 * board; `$scopeId` is the scope step's choice, null without one.
 */
final class BoardCreatedEvent extends Event
{
    public function __construct(
        public readonly TaskBoard $board,
        public readonly object $actor,
        public readonly ?string $scopeId = null,
    ) {
    }
}
