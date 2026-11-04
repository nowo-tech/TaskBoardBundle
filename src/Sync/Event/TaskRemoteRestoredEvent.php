<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Sync\Event;

use Nowo\TaskBoardBundle\Entity\Task;
use Nowo\TaskBoardBundle\Event\TaskBoardEvents;
use Symfony\Contracts\EventDispatcher\Event;

/** Since 1.23: {@see TaskBoardEvents::TASK_REMOTE_RESTORED}, after flush. */
final class TaskRemoteRestoredEvent extends Event
{
    public function __construct(
        public readonly Task $task,
        public readonly string $provider,
        public readonly string $externalId,
        public readonly string $runId,
    ) {
    }
}
