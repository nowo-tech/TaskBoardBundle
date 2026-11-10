<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Event;

/**
 * Since 2.11.0 (DDD audit G3): {@see TaskBoardEvents::TASK_DEPENDENCY_REMOVED} — a dependency was removed, after it is
 * saved. Ids only; the payload is the dependency as it was.
 */
final class TaskDependencyRemovedEvent extends AbstractTaskDependencyEvent
{
}
