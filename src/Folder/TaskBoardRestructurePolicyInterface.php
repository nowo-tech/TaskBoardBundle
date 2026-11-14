<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Folder;

use Nowo\TaskBoardBundle\ValueObject\TaskBoardSnapshot;

/**
 * Since 2.18.0 (Beacon spec 234, FR-031 / FR-045): who may restructure a tenant's boards — manage its folders, move a board
 * into or out of a folder, move tasks between its boards. Framework-free (REQ-ARCH-001 rules 1 and 3): the user is the
 * host's own object, the board a {@see TaskBoardSnapshot}.
 *
 * Set `restructure.policy` to a service implementing it. The default ({@see ManageRightRestructurePolicy}) is the
 * existing manage right: the create-board decision on the tenant for folders, `canManageBoard()` for a board.
 */
interface TaskBoardRestructurePolicyInterface
{
    /** Create, rename, describe, reorder or delete the folders of the tenant (`null`: boards without a tenant). */
    public function mayManageFolders(object $user, ?string $tenantKey): bool;

    /** Move this board into or out of a folder, or move tasks from or to it. Asked of every board a move touches. */
    public function mayRestructureBoard(object $user, TaskBoardSnapshot $board): bool;
}
