<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Event;

/**
 * Since 2.11.0 (DDD audit G3): {@see TaskBoardEvents::TASK_LINK_REMOVED} — a link was removed from a task, after it is saved. Ids only.
 */
final class TaskLinkRemovedEvent extends AbstractTaskLinkEvent
{
}
