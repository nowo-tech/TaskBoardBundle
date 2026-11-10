<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Event;

/**
 * Since 2.11.0 (DDD audit G3): {@see TaskBoardEvents::TASK_DEPENDENCY_ADDED} — a dependency was added, after it is saved.
 * Ids only.
 */
final class TaskDependencyAddedEvent extends AbstractTaskDependencyEvent
{
}
