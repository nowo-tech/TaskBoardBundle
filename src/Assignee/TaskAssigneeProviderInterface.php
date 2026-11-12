<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Assignee;

use Nowo\TaskBoardBundle\Entity\TaskBoard;

/**
 * Who may be a member of a task on a board (`assignee_provider`, since 1.10), e.g. the members of the host project
 * the board belongs to. Without one, every user of `user_class` may be added.
 *
 * @deprecated since 2.15.0, implement the framework-free {@see TaskAssigneePolicyInterface} instead (REQ-ARCH-001); removed in 3.0. A
 *             service implementing this interface is still asked through it, exactly as before.
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
