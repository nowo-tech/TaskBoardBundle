<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Event;

/**
 * Since 2.11.0 (DDD audit G3): {@see TaskBoardEvents::TASK_TIME_REMOVED} — a manual time entry was removed from a task (the
 * row is deleted), after it is saved. Ids only; the payload is the entry as it was.
 */
final class TaskTimeRemovedEvent extends AbstractTaskTimeEntryEvent
{
}
