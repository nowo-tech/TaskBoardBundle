<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Sync\Event;

use Nowo\TaskBoardBundle\Entity\TaskBoard;
use Nowo\TaskBoardBundle\Event\TaskBoardEvents;
use Symfony\Contracts\EventDispatcher\Event;

/**
 * Since 1.23: {@see TaskBoardEvents::BOARD_CONNECTION_LINKED} /
 * {@see TaskBoardEvents::BOARD_CONNECTION_UNLINKED} (for the host's audit).
 */
final class BoardConnectionEvent extends Event
{
    public function __construct(
        public readonly TaskBoard $board,
        public readonly string $provider,
        public readonly string $containerId,
        public readonly string $containerLabel,
        public readonly string $connectionRef,
        public readonly object $actor,
    ) {
    }
}
