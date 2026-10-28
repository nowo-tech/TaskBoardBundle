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
    case StartDate        = 'start_date';
    case EstimatedMinutes = 'estimated_minutes';
    case Tags             = 'tags';
    case Color            = 'color';
    case Completed        = 'completed';
    case LinkAdded        = 'link_added';
    case LinkUpdated      = 'link_updated';
    case LinkRemoved      = 'link_removed';
    case MemberAdded      = 'member_added';
    case MemberRemoved    = 'member_removed';
    // Since 1.15.
    case DependencyAdded   = 'dependency_added';
    case DependencyRemoved = 'dependency_removed';
    case TimeLogged        = 'time_logged';
    case TimeRemoved       = 'time_removed';
}
