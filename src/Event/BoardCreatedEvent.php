<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Event;

use Nowo\TaskBoardBundle\Entity\TaskBoard;
use Nowo\TaskBoardBundle\Port\Snapshots;
use Nowo\TaskBoardBundle\Support\ActorId;
use Nowo\TaskBoardBundle\ValueObject\TaskBoardSnapshot;
use Symfony\Contracts\EventDispatcher\Event;

/**
 * Since 1.30.0 (spec 211): a board was created and saved (`TaskBoardEvents::BOARD_CREATED`), by any path. The tenant is on the
 * board; `$scopeId` is the scope step's choice, null without one.
 */
final class BoardCreatedEvent extends Event
{
    public function __construct(
        /** @deprecated since 2.15.0, use getBoardId(), getTenantKey() or getBoardSnapshot(); removed in 3.0 */
        public readonly TaskBoard $board,
        public readonly object $actor,
        public readonly ?string $scopeId = null,
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

    /** Since 2.15.0: the board as a framework-free snapshot, copied on each call. */
    public function getBoardSnapshot(): TaskBoardSnapshot
    {
        return Snapshots::board($this->board);
    }
}
