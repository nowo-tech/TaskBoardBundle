<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Enum;

/**
 * Types of auditable changes on a task.
 */
enum TaskChangeType: string
{
    case Created          = 'created';
    case Title            = 'title';
    case Description      = 'description';
    case Priority         = 'priority';
    case Column           = 'column';
    case DueDate          = 'due_date';
    case EstimatedMinutes = 'estimated_minutes';
    case Tags             = 'tags';
    case Completed        = 'completed';
    case LinkAdded        = 'link_added';
    case LinkUpdated      = 'link_updated';
    case LinkRemoved      = 'link_removed';
    case MemberAdded      = 'member_added';
    case MemberRemoved    = 'member_removed';
}
