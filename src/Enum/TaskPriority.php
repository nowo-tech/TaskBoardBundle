<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Enum;

/**
 * Task priority levels for sorting and visual indicators.
 */
enum TaskPriority: string
{
    case Low    = 'low';
    case Normal = 'normal';
    case High   = 'high';
    case Urgent = 'urgent';
}
