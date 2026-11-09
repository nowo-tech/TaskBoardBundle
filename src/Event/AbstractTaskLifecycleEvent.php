<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Event;

use Nowo\TaskBoardBundle\Entity\Task;
use Nowo\TaskBoardBundle\Service\TaskChangeContext;
use Nowo\TaskBoardBundle\Support\ActorId;
use Symfony\Contracts\EventDispatcher\Event;

/**
 * Since 2.10.0 (spec 216, DDD audit G2/G3): a task's state changed, with ids only, so a host translates it without touching
 * the bundle's entities. Dispatched after the write is saved (after the commit inside a multi-step write). `cause` is
 * {@see TaskChangeContext::current()} when the write ran (e.g. "rule:42"), null otherwise.
 */
abstract class AbstractTaskLifecycleEvent extends Event
{
    final public function __construct(
        public readonly string $taskId,
        public readonly string $boardId,
        public readonly ?string $tenantKey,
        public readonly ?int $number,
        public readonly ?string $actorId,
        public readonly ?string $cause = null,
    ) {
    }

    public static function of(Task $task, ?object $actor, ?string $cause): static
    {
        $board = $task->getBoard();

        return new static($task->getId(), $board->getId(), $board->getTenantKey(), $task->getNumber(), ActorId::of($actor), $cause);
    }
}
