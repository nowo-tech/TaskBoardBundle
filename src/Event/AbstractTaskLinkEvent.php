<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Event;

use Nowo\TaskBoardBundle\Entity\TaskLink;
use Nowo\TaskBoardBundle\Enum\TaskLinkType;
use Nowo\TaskBoardBundle\Service\TaskChangeContext;
use Nowo\TaskBoardBundle\Support\ActorId;
use Symfony\Contracts\EventDispatcher\Event;

/**
 * Since 2.11.0 (DDD audit G3): a task link (merge request, pull request, URL, host object) was added, changed or removed,
 * with ids and scalars only, after the write is saved (after the commit inside a deferred write). `linkType` is the
 * {@see TaskLinkType} value; `objectType` / `objectId` name the host object of a host-object
 * link (null otherwise). The URL and the label are not in the payload. `cause` is {@see TaskChangeContext::current()} at
 * the write.
 */
abstract class AbstractTaskLinkEvent extends Event
{
    final public function __construct(
        public readonly string $linkId,
        public readonly string $taskId,
        public readonly string $boardId,
        public readonly ?string $tenantKey,
        public readonly string $linkType,
        public readonly ?string $objectType,
        public readonly ?string $objectId,
        public readonly ?string $actorId,
        public readonly ?string $cause = null,
    ) {
    }

    public static function of(TaskLink $link, ?object $actor, ?string $cause): static
    {
        $task  = $link->getTask();
        $board = $task->getBoard();

        return new static($link->getId(), $task->getId(), $board->getId(), $board->getTenantKey(), $link->getLinkType()->value, $link->getObjectType(), $link->getObjectId(), ActorId::of($actor), $cause);
    }
}
