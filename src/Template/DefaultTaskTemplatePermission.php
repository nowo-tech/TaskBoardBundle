<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Template;

use Nowo\TaskBoardBundle\Entity\TaskBoard;
use Nowo\TaskBoardBundle\Repository\TaskBoardRepositoryInterface;
use Nowo\TaskBoardBundle\Security\AllowAllTaskBoardAccessChecker;
use Nowo\TaskBoardBundle\Security\ConfigurableTaskBoardAccessChecker;
use Nowo\TaskBoardBundle\Security\TaskBoardAccessCheckerInterface;
use Nowo\TaskBoardBundle\Security\TaskBoardResourceAccessChecker;
use Nowo\TaskBoardBundle\Security\TenantAwareTaskBoardAccessCheckerInterface;
use Nowo\TaskBoardBundle\ValueObject\TaskBoardSnapshot;

/**
 * Since 2.16.0 (Beacon spec 234, FR-061): the default {@see TaskTemplatePermissionInterface}, on the rights the bundle
 * already has (deny when the board is gone):
 *
 * - a board's templates: {@see TaskBoardResourceAccessChecker::canManageBoard()};
 * - a tenant's templates: {@see TaskBoardResourceAccessChecker::canManageTenantTemplates()} (a `security.manage_roles`
 *   holder, or anyone when `security.resource_scoping` is off) and, since audit PC-L3, for a tenant key, the host's own
 *   tenant-aware access checker ({@see TenantAwareTaskBoardAccessCheckerInterface}, `security.access_checker`) letting the
 *   person list that tenant's boards, so a manager of one tenant does not manage another tenant's templates (the bundled
 *   checkers ignore the tenant, so they decide nothing here);
 * - using a template: {@see TaskBoardResourceAccessChecker::canEditBoard()} (the right to add tasks).
 *
 * Stateless: the board is read again from its repository (the identity map), never kept.
 */
final readonly class DefaultTaskTemplatePermission implements TaskTemplatePermissionInterface
{
    public function __construct(
        private TaskBoardResourceAccessChecker $access,
        private TaskBoardRepositoryInterface $boards,
        // Since 2.16.0 (audit PC-L3): the host's access checker, asked whether the person reaches a tenant.
        private ?TaskBoardAccessCheckerInterface $accessChecker = null,
    ) {
    }

    public function canManage(object $user, TaskTemplateScope $scope): bool
    {
        if (!$scope->board instanceof TaskBoardSnapshot) {
            return $this->access->canManageTenantTemplates($user, $scope->tenantKey) && $this->reachesTenant($user, $scope->tenantKey);
        }
        $board = $this->board($scope->board);

        return $board instanceof TaskBoard && $this->access->canManageBoard($user, $board);
    }

    public function canUse(object $user, TaskBoardSnapshot $board, TaskTemplateSnapshot $template): bool
    {
        $entity = $this->board($board);

        return $entity instanceof TaskBoard && $this->access->canEditBoard($user, $entity);
    }

    /**
     * Since 2.16.0 (audit PC-L3): a tenant the person may list the boards of. No tenant, a checker that does not know
     * tenants, or a bundled checker (they ignore the tenant) decides nothing here.
     */
    private function reachesTenant(object $user, ?string $tenantKey): bool
    {
        $checker = $this->accessChecker;
        if ($tenantKey === null || !$checker instanceof TenantAwareTaskBoardAccessCheckerInterface || $checker instanceof ConfigurableTaskBoardAccessChecker || $checker instanceof AllowAllTaskBoardAccessChecker) {
            return true;
        }

        return $checker->canListBoards($user, $tenantKey);
    }

    private function board(TaskBoardSnapshot $snapshot): ?TaskBoard
    {
        $board = $this->boards->findById($snapshot->id);

        // REQ-SEC-006: the id read back is the one asked, byte for byte.
        return $board instanceof TaskBoard && $board->getId() === $snapshot->id ? $board : null;
    }
}
