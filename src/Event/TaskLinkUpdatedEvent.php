<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Event;

/**
 * Since 2.11.0 (DDD audit G3): {@see TaskBoardEvents::TASK_LINK_UPDATED} — a link was changed (type, URL or label), after it is saved. Ids only.
 */
final class TaskLinkUpdatedEvent extends AbstractTaskLinkEvent
{
}
