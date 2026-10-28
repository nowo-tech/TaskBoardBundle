<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Event;

/**
 * A completed task left the last ("done") column — {@see TaskBoardEvents::TASK_REOPENED}.
 */
final class TaskReopenedEvent extends AbstractTaskEvent
{
}
