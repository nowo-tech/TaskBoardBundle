<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Dto;

use Nowo\TaskBoardBundle\Enum\TaskDependencyType;

/**
 * Since 1.15: form payload of the "Add dependency" dialog — "this task <type> the task `taskId`".
 */
final class TaskDependencyFormData
{
    public function __construct(
        public TaskDependencyType $type = TaskDependencyType::Blocks,
        public ?string $taskId = null,
    ) {
    }
}
