<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Dto;

use Nowo\TaskBoardBundle\Enum\TaskMemberRole;

/**
 * Form payload for associating a user with a task.
 *
 * Since 2.29.0 (DDD-host checklist B1) the user may be given by id: `$userId` (the host user's `getId()`, exact) when
 * `$user` is null. The assigner looks the id up again (`user_directory` / `user_class`); an id that names nobody is
 * refused like a user the assignee provider refuses. Passing the user object stays supported in 3.x
 * (a 3.x minor deprecates it; removed in 4.0 in favour of the id).
 */
final class TaskMemberFormData
{
    /**
     * @param object|null $user the host user (kept in 3.x, deprecated by a 3.x minor in favour of `$userId`, removed in 4.0)
     * @param string|null $userId since 2.29.0: the host user's id, used when `$user` is null
     */
    public function __construct(
        public ?object $user = null,
        public TaskMemberRole $memberRole = TaskMemberRole::Assignee,
        public ?string $userId = null,
    ) {
    }
}
