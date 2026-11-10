<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Event;

/**
 * Since 2.10.0 (spec 216): {@see TaskBoardEvents::TASK_ARCHIVED} — a task was archived by hand (the board, the API), after it is
 * saved. A provider sync's archived task dispatches TASK_REMOTE_ARCHIVED instead.
 */
final class TaskArchivedEvent extends AbstractTaskLifecycleEvent
{
}
