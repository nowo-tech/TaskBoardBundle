<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Assignee;

use Nowo\TaskBoardBundle\ValueObject\TaskBoardSnapshot;

/**
 * Since 2.15.0 (REQ-ARCH-001 rule 1): the framework-free twin of `TaskAssigneeProviderInterface` (the 2.14 interface, removed in 3.0): who may be a member
 * of a task on a board, given as a {@see TaskBoardSnapshot} instead of the entity.
 *
 * Set `assignee_provider` to a service implementing it.
 */
interface TaskAssigneePolicyInterface
{
    /**
     * The users the member picker offers for the tasks of the board (instances of `user_class`).
     *
     * @return list<object>
     */
    public function assignableUsers(TaskBoardSnapshot $board): array;

    /** Whether `$user` may be added as a member of a task of the board. */
    public function canBeAssigned(TaskBoardSnapshot $board, object $user): bool;
}
