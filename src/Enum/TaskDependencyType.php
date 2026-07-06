<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Enum;

/**
 * Relationship between two tasks.
 */
enum TaskDependencyType: string
{
    case Blocks     = 'blocks';
    case BlockedBy  = 'blocked_by';
    case Related    = 'related';
    case Duplicates = 'duplicates';
}
