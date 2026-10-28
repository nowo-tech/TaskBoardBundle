<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Assignee;

use Nowo\TaskBoardBundle\Entity\TaskBoard;

/**
 * Who may be a member of a task on a board (`assignee_provider`, since 1.10), e.g. the members of the host project
 * the board belongs to. Without one, every user of `user_class` may be added.
 */
interface TaskAssigneeProviderInterface
{
    /**
     * The users the member picker offers for the tasks of `$board` (instances of `user_class`).
     *
     * @return list<object>
     */
    public function assignableUsers(TaskBoard $board): array;

    /**
     * Whether `$user` may be added as a member of a task of `$board`.
     */
    public function canBeAssigned(TaskBoard $board, object $user): bool;
}
