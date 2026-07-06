<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Enum;

/**
 * Role of a user associated with a task.
 */
enum TaskMemberRole: string
{
    case Assignee    = 'assignee';
    case Reviewer    = 'reviewer';
    case Stakeholder = 'stakeholder';
    case Watcher     = 'watcher';
}
