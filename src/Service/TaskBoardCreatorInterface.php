<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Service;

use Nowo\TaskBoardBundle\Dto\TaskBoardFormData;
use Nowo\TaskBoardBundle\Entity\ColumnSet;
use Nowo\TaskBoardBundle\Entity\TaskBoard;

/**
 * Since 2.10.0 (DDD audit G4): the port of {@see TaskBoardCreator}, which creates boards. A host's application layer
 * depends on it and can double or decorate it; the container aliases it to the bundle's class. The bundle's own
 * controllers keep using the class, so a host decorator wraps the host's calls only.
 *
 * No access check (audit JY-L2): like the class, it trusts its caller. Before calling it the host checks what the bundle's
 * controllers check: `TaskBoardResourceAccessChecker` (`canEditTask` / `canEditBoard` / `canManageBoard` /
 * `canManageColumns` / `canTrackTime`), tenant ownership and plan, comment rights (`comments.readers_can_comment`), the
 * active-task quota (`TaskManager::activeTaskRefusal()` before a create or restore) and CSRF.
 */
interface TaskBoardCreatorInterface
{
    /**
     * Creates a board with the default columns, or the columns of `$columnSet` (since 1.11) when it has any;
     * `$tenantKey` puts it in a host tenant (`tenancy`).
     */
    public function create(TaskBoardFormData $data, object $creator, ?string $tenantKey = null, ?ColumnSet $columnSet = null): TaskBoard;
}
