<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Import;

use Nowo\TaskBoardBundle\ValueObject\TaskBoardSnapshot;

/**
 * Since 2.15.0 (REQ-ARCH-001 rule 1): the framework-free twin of {@see TaskImportLinkLookupInterface}: which source ids an
 * import finds already imported into another board of the same tenant, given the target board as a
 * {@see TaskBoardSnapshot}.
 *
 * Alias `Nowo\TaskBoardBundle\Import\TaskImportLinkLookupInterface` to a service implementing it, as for the deprecated
 * interface; the bundle asks it through an internal gate.
 */
interface TaskImportLinkFinderInterface
{
    /**
     * @param list<string> $externalIds
     *
     * @return array<string, true> the ids of `$externalIds` linked, with the scope `$scope` (or a pre-1.22 label of
     *                             that provider), to a task of another board of the tenant
     */
    public function findOnOtherBoards(TaskBoardSnapshot $board, string $scope, array $externalIds): array;
}
