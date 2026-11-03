<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Import;

use Nowo\TaskBoardBundle\Entity\TaskBoard;

/**
 * Since 1.22: which source ids an import finds already imported into another board of the same tenant (the boards
 * whose `tenant_key` equals the target board's, null included), so it neither moves nor copies those tasks.
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
