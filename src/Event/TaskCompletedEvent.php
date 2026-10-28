<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Event;

/**
 * A task reached the last ("done") column — {@see TaskBoardEvents::TASK_COMPLETED}.
 */
final class TaskCompletedEvent extends AbstractTaskEvent
{
}
