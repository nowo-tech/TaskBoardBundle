<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Event;

/**
 * Since 2.10.0 (spec 216): {@see TaskBoardEvents::TASK_RESTORED} — a task was restored by hand (the board, the API), after it is
 * saved. A provider sync's restored task dispatches TASK_REMOTE_RESTORED instead.
 */
final class TaskRestoredEvent extends AbstractTaskLifecycleEvent
{
}
