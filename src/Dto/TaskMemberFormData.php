<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Dto;

use Nowo\TaskBoardBundle\Enum\TaskMemberRole;

/**
 * Form payload for associating a user with a task.
 */
final class TaskMemberFormData
{
    public function __construct(
        public ?object $user = null,
        public TaskMemberRole $memberRole = TaskMemberRole::Assignee,
    ) {
    }
}
