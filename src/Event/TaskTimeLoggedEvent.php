<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Event;

/**
 * Since 2.11.0 (DDD audit G3): {@see TaskBoardEvents::TASK_TIME_LOGGED} — a manual time entry was logged on a task, after it
 * is saved. Ids only.
 */
final class TaskTimeLoggedEvent extends AbstractTaskTimeEntryEvent
{
}
