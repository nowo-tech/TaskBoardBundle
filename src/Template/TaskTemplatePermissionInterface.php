<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Template;

use Nowo\TaskBoardBundle\ValueObject\TaskBoardSnapshot;

/**
 * Since 2.16.0 (Beacon spec 234, FR-061/FR-062): who may manage task templates and who may create a task from one.
 * Framework-free: the board and the template are value objects; `$user` is an instance of `user_class` (or the user
 * reference the bundle holds).
 *
 * Set `task_templates.permission` to a service implementing it. The default ({@see DefaultTaskTemplatePermission}) falls
 * back to the rights the bundle already has: a board's templates are managed with the right to manage the board,
 * a tenant's with `security.manage_roles`, and a template is used with the right to add tasks to the board.
 *
 * Called once per decision, in the request that asks it: an implementation keeps no state between calls (worker mode).
 */
interface TaskTemplatePermissionInterface
{
    /** Whether `$user` may create, change and delete the templates of this scope. */
    public function canManage(object $user, TaskTemplateScope $scope): bool;

    /**
     * Whether `$user` may see `$template` in the picker of `$board` and create a task from it. Only asked of a template
     * that belongs to the board or to the board's tenant: a template of another board or tenant is never offered.
     */
    public function canUse(object $user, TaskBoardSnapshot $board, TaskTemplateSnapshot $template): bool;
}
