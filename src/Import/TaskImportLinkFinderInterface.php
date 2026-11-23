<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Import;

use Nowo\TaskBoardBundle\ValueObject\TaskBoardSnapshot;

/**
 * Since 2.15.0 (REQ-ARCH-001 rule 1): the framework-free twin of `TaskImportLinkLookupInterface` (the 2.14 interface, removed in 3.0): which source ids an
 * import finds already imported into another board of the same tenant, given the target board as a
 * {@see TaskBoardSnapshot}.
 *
 * Alias `Nowo\TaskBoardBundle\Import\TaskImportLinkFinderInterface` to a service implementing it (since 3.0.0; up to 2.x the
 * removed 2.14 interface was aliased); the bundle asks it through an internal gate.
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
