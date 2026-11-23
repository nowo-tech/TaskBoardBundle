<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Port\Internal;

use Nowo\TaskBoardBundle\Assignee\TaskAssigneePolicyInterface;
use Nowo\TaskBoardBundle\Entity\TaskBoard;

/**
 * Who may be a member of a task on a board (`assignee_provider`, since 1.10), e.g. the members of the host project
 * the board belongs to. Without one, every user of `user_class` may be added.
 *
 * Since 3.0.0 the bundle's own entity-typed seam (REQ-ARCH-001 rule 3): it was the host port `Nowo\TaskBoardBundle\Assignee\TaskAssigneeProviderInterface`,
 * deprecated in 2.15.0 and removed in 3.0. Hosts implement the framework-free {@see TaskAssigneePolicyInterface};
 * the bundle asks it through its gate, and its own defaults implement this seam. A class outside the bundle that
 * implements this interface is refused when the container is built.
 *
 * @internal
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
