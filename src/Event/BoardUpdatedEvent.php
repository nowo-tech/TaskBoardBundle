<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Event;

use Nowo\TaskBoardBundle\Entity\TaskBoard;
use Nowo\TaskBoardBundle\Support\ActorId;
use Symfony\Contracts\EventDispatcher\Event;

/**
 * Since 2.2.0 (spec 213): a board was renamed or its description changed ({@see TaskBoardEvents::BOARD_UPDATED}).
 */
final class BoardUpdatedEvent extends Event
{
    /**
     * @param list<string> $changed the fields that changed: `name`, `description`
     */
    public function __construct(
        public readonly TaskBoard $board,
        public readonly object $actor,
        public readonly array $changed,
    ) {
    }

    /** Since 2.10.0 (DDD audit G2). */
    public function getBoardId(): string
    {
        return $this->board->getId();
    }

    /** Since 2.10.0 (DDD audit G2). */
    public function getTenantKey(): ?string
    {
        return $this->board->getTenantKey();
    }

    /** Since 2.10.0 (DDD audit G2): the actor's `getId()` as a string. */
    public function getActorId(): ?string
    {
        return ActorId::of($this->actor);
    }
}
