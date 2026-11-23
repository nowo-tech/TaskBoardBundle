<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Event;

use DateTimeImmutable;
use Nowo\TaskBoardBundle\Service\TaskChangeContext;
use Symfony\Contracts\EventDispatcher\Event;

/**
 * Since 2.10.0 (spec 216, DDD audit G2/G3): a board's columns changed, with ids only. Dispatched after the write is saved
 * (after the commit of a sync run or an import that adds columns). `actorId` is null for a write with no user (a sync run
 * from a worker); `cause` is {@see TaskChangeContext::current()} when the write ran.
 */
abstract class AbstractColumnEvent extends Event
{
    public function __construct(
        public readonly string $boardId,
        public readonly ?string $tenantKey,
        public readonly ?string $actorId,
        public readonly ?string $cause,
        /**
         * Since 2.19.0 (audit PW-H1): the person or service account that made the change (null: a worker, an import), so
         * a listener that writes more on its behalf (the shared status set of a folder) can ask its rights. Not for
         * serialisation: copy `actorId`. Since 3.0.0 no longer public (deprecated since 2.29.0): read
         * {@see self::getActorId()}.
         */
        private readonly ?object $actor = null,
    ) {
        $this->occurredAt = new DateTimeImmutable();
    }

    /** Since 2.29.0 (DDD-host checklist B4): when the change happened (the event's creation, right after the write). */
    public readonly DateTimeImmutable $occurredAt;

    /**
     * Since 3.0.0: the actor object, for the bundle's own listeners that write more on its behalf (the public property
     * was removed in 3.0).
     *
     * @internal
     */
    public function actorObject(): ?object
    {
        return $this->actor;
    }

    /** Since 2.29.0 (DDD-host checklist B4). */
    public function getBoardId(): string
    {
        return $this->boardId;
    }

    /** Since 2.29.0 (DDD-host checklist B4): the acting user's id; null for a write without a user. */
    public function getActorId(): ?string
    {
        return $this->actorId;
    }

    /** Since 2.29.0 (DDD-host checklist B4). */
    public function getOccurredAt(): DateTimeImmutable
    {
        return $this->occurredAt;
    }
}
