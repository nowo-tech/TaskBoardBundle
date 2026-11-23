<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Port\Internal;

use Nowo\TaskBoardBundle\Entity\TaskBoard;
use Nowo\TaskBoardBundle\Import\TaskImportLinkFinderInterface;

/**
 * Since 1.22: which source ids an import finds already imported into another board of the same tenant (the boards
 * whose `tenant_key` equals the target board's, null included), so it neither moves nor copies those tasks.
 *
 * Since 3.0.0 the bundle's own entity-typed seam (REQ-ARCH-001 rule 3): it was the host port `Nowo\TaskBoardBundle\Import\TaskImportLinkLookupInterface`,
 * deprecated in 2.15.0 and removed in 3.0. Hosts implement the framework-free {@see TaskImportLinkFinderInterface};
 * the bundle asks it through its gate, and its own defaults implement this seam. A class outside the bundle that
 * implements this interface is refused when the container is built.
 *
 * @internal
 */
interface TaskImportLinkLookupInterface
{
    /**
     * @param list<string> $externalIds
     *
     * @return array<string, true> the ids of `$externalIds` linked, with the scope `$scope` (or a pre-1.22 label of
     *                             that provider), to a task of another board of the tenant
     */
    public function findOnOtherBoards(TaskBoard $board, string $scope, array $externalIds): array;
}
