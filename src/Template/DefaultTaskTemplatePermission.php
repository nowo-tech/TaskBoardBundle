<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Template;

use Nowo\TaskBoardBundle\Entity\TaskBoard;
use Nowo\TaskBoardBundle\Repository\TaskBoardRepositoryInterface;
use Nowo\TaskBoardBundle\Security\TaskBoardResourceAccessChecker;
use Nowo\TaskBoardBundle\ValueObject\TaskBoardSnapshot;

/**
 * Since 2.16.0 (Beacon spec 234, FR-061): the default {@see TaskTemplatePermissionInterface}, on the rights the bundle
 * already has (deny when the board is gone):
 *
 * - a board's templates: {@see TaskBoardResourceAccessChecker::canManageBoard()};
 * - a tenant's templates: {@see TaskBoardResourceAccessChecker::canManageTenantTemplates()} (a `security.manage_roles`
 *   holder, or anyone when `security.resource_scoping` is off);
 * - using a template: {@see TaskBoardResourceAccessChecker::canEditBoard()} (the right to add tasks).
 *
 * Stateless: the board is read again from its repository (the identity map), never kept.
 */
final readonly class DefaultTaskTemplatePermission implements TaskTemplatePermissionInterface
{
    public function __construct(
        private TaskBoardResourceAccessChecker $access,
        private TaskBoardRepositoryInterface $boards,
    ) {
    }

    public function canManage(object $user, TaskTemplateScope $scope): bool
    {
        if (!$scope->board instanceof TaskBoardSnapshot) {
            return $this->access->canManageTenantTemplates($user, $scope->tenantKey);
        }
        $board = $this->board($scope->board);

        return $board instanceof TaskBoard && $this->access->canManageBoard($user, $board);
    }

    public function canUse(object $user, TaskBoardSnapshot $board, TaskTemplateSnapshot $template): bool
    {
        $entity = $this->board($board);

        return $entity instanceof TaskBoard && $this->access->canEditBoard($user, $entity);
    }

    private function board(TaskBoardSnapshot $snapshot): ?TaskBoard
    {
        $board = $this->boards->findById($snapshot->id);

        // REQ-SEC-006: the id read back is the one asked, byte for byte.
        return $board instanceof TaskBoard && $board->getId() === $snapshot->id ? $board : null;
    }
}
