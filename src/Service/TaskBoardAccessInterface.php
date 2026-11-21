<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Service;

use Nowo\TaskBoardBundle\Security\TaskBoardAccessCheckerInterface;
use Nowo\TaskBoardBundle\Security\TaskBoardResourceAccessChecker;
use Nowo\TaskBoardBundle\ValueObject\UserRef;

/**
 * Since 2.29.0 (DDD-host checklist B8, REQ-ARCH-001 rules 1 and 3): the bundle's access decisions for a user given as a
 * {@see UserRef} and a resource given by id. The same rules as the bundle's pages: the global gate
 * ({@see TaskBoardAccessCheckerInterface}) first, then {@see TaskBoardResourceAccessChecker} with the
 * BOARD_ACCESS_CHECK / TASK_ACCESS_CHECK listeners (house access pattern). Role rules use the roles of that user, never
 * the signed-in session's (2.29.0 fix). Implemented by {@see TaskBoardIdAccessChecker}.
 *
 * The user is looked up again by id (`user_directory` / `user_class`); an unknown user or an unknown resource gets
 * false, never an exception, so an id never confirms that something exists.
 */
interface TaskBoardAccessInterface
{
    /** The global gate (`security.access_roles` or the host's checker). */
    public function canAccess(UserRef $user): bool;

    public function canViewBoard(UserRef $user, string $boardId): bool;

    /** Change the board: columns, new tasks, import (view access not marked read-only). */
    public function canEditBoard(UserRef $user, string $boardId): bool;

    /** Empty, delete or manage the board (creator or `security.manage_roles` by default). */
    public function canManageBoard(UserRef $user, string $boardId): bool;

    public function canViewTask(UserRef $user, string $taskId): bool;

    public function canEditTask(UserRef $user, string $taskId): bool;

    /** Log time on the task. */
    public function canTrackTime(UserRef $user, string $taskId): bool;

    /**
     * Of these boards, the ids the user may view, in the given order (unknown ids left out). At most 500 ids.
     *
     * @param list<string> $boardIds
     *
     * @return list<string>
     */
    public function filterBoardIds(UserRef $user, array $boardIds): array;
}
