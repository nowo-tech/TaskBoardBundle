<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Event;

use Nowo\TaskBoardBundle\Entity\Task;
use Nowo\TaskBoardBundle\Entity\TaskTimeEntry;
use Nowo\TaskBoardBundle\Service\TaskChangeContext;
use Nowo\TaskBoardBundle\Support\ActorId;
use Symfony\Contracts\EventDispatcher\Event;

/**
 * Since 2.11.0 (DDD audit G3): a manual time entry of a task was logged or removed, with ids and scalars only, after the
 * write is saved (after the commit inside a deferred write). `userId` is whose time it is (the entry's user), `actorId`
 * who made the change; `loggedOn` is the entry's day (`Y-m-d`). `cause` is {@see TaskChangeContext::current()} at the write.
 */
abstract class AbstractTaskTimeEntryEvent extends Event
{
    final public function __construct(
        public readonly string $entryId,
        public readonly string $taskId,
        public readonly string $boardId,
        public readonly ?string $tenantKey,
        public readonly ?string $userId,
        public readonly int $minutes,
        public readonly string $loggedOn,
        public readonly ?string $actorId,
        public readonly ?string $cause = null,
    ) {
    }

    public static function of(Task $task, TaskTimeEntry $entry, ?object $actor, ?string $cause): static
    {
        $board = $task->getBoard();

        return new static($entry->getId(), $task->getId(), $board->getId(), $board->getTenantKey(), ActorId::of($entry->getUser()), $entry->getMinutes(), $entry->getLoggedAt()->format('Y-m-d'), ActorId::of($actor), $cause);
    }
}
