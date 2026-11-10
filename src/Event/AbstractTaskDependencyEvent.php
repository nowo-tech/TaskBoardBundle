<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Event;

use Nowo\TaskBoardBundle\Entity\Task;
use Nowo\TaskBoardBundle\Entity\TaskDependency;
use Nowo\TaskBoardBundle\Service\TaskChangeContext;
use Nowo\TaskBoardBundle\Support\ActorId;
use Symfony\Contracts\EventDispatcher\Event;

/**
 * Since 2.11.0 (DDD audit G3): a dependency between two tasks of a board was added or removed, with ids and scalars only,
 * after the write is saved (after the commit inside a deferred write, a sync page or an import). The link reads
 * "`sourceTaskId` <type> `targetTaskId`" (`type`: `blocks`, `blocked_by`, `related`, `duplicates`); `taskId` is the task the
 * change was made on (either end). `cause` is {@see TaskChangeContext::current()} at the write.
 */
abstract class AbstractTaskDependencyEvent extends Event
{
    final public function __construct(
        public readonly string $dependencyId,
        public readonly string $taskId,
        public readonly string $sourceTaskId,
        public readonly string $targetTaskId,
        public readonly string $type,
        public readonly string $boardId,
        public readonly ?string $tenantKey,
        public readonly ?string $actorId,
        public readonly ?string $cause = null,
    ) {
    }

    public static function of(Task $task, TaskDependency $dependency, ?object $actor, ?string $cause): static
    {
        $board = $task->getBoard();

        return new static($dependency->getId(), $task->getId(), $dependency->getSourceTask()->getId(), $dependency->getTargetTask()->getId(), $dependency->getDependencyType()->value, $board->getId(), $board->getTenantKey(), ActorId::of($actor), $cause);
    }
}
