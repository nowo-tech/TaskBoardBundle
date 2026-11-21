<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Enum;

/**
 * Since 2.29.0: order of a task query of the host read port. Every order ends with the task id, so a page is stable.
 */
enum TaskSort: string
{
    case CreatedDesc = 'created_desc';
    case CreatedAsc  = 'created_asc';
    case UpdatedDesc = 'updated_desc';
    case DueAsc      = 'due_asc';
}
