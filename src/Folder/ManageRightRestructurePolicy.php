<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Folder;

use Nowo\TaskBoardBundle\Entity\TaskBoard;
use Nowo\TaskBoardBundle\Repository\TaskBoardRepositoryInterface;
use Nowo\TaskBoardBundle\Security\TaskBoardAccessCheckerInterface;
use Nowo\TaskBoardBundle\Security\TaskBoardResourceAccessChecker;
use Nowo\TaskBoardBundle\Security\TenantAwareTaskBoardAccessCheckerInterface;
use Nowo\TaskBoardBundle\Tenancy\TaskBoardTenant;
use Nowo\TaskBoardBundle\Tenancy\TaskBoardTenantContext;
use Nowo\TaskBoardBundle\ValueObject\TaskBoardSnapshot;

/**
 * Since 2.19.0: the default {@see TaskBoardRestructurePolicyInterface} — the manage right the bundle already has.
 *
 * - Folders: the create-board decision (`TenantAwareTaskBoardAccessCheckerInterface::canCreateBoard()` with the tenant),
 *   and, with tenancy, a tenant the user opens.
 * - A board: `TaskBoardResourceAccessChecker::canManageBoard()` (its creator or a `security.manage_roles` holder by
 *   default, as `BOARD_ACCESS_CHECK` decides), on the board as the database holds it now.
 *
 * Stateless (REQ-RUNTIME-002).
 */
final readonly class ManageRightRestructurePolicy implements TaskBoardRestructurePolicyInterface
{
    public function __construct(
        private TaskBoardAccessCheckerInterface $accessChecker,
        private TaskBoardResourceAccessChecker $resourceAccess,
        private TaskBoardRepositoryInterface $boards,
        private ?TaskBoardTenantContext $tenantContext = null,
    ) {
    }

    public function mayManageFolders(object $user, ?string $tenantKey): bool
    {
        if ($this->tenantContext?->isEnabled() === true && ($tenantKey === null || !$this->tenantContext->tenantOfKey($tenantKey, $user) instanceof TaskBoardTenant)) {
            return false;
        }

        return $this->accessChecker instanceof TenantAwareTaskBoardAccessCheckerInterface
            ? $this->accessChecker->canCreateBoard($user, $tenantKey)
            : $this->accessChecker->canCreateBoard($user);
    }

    public function mayRestructureBoard(object $user, TaskBoardSnapshot $board): bool
    {
        $entity = $this->boards->findById($board->id);

        return $entity instanceof TaskBoard && $entity->getTenantKey() === $board->tenantKey && $this->resourceAccess->canManageBoard($user, $entity);
    }
}
