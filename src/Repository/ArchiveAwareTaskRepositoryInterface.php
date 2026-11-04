<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Repository;

use Nowo\TaskBoardBundle\Entity\Task;
use Nowo\TaskBoardBundle\Entity\TaskBoard;
use Nowo\TaskBoardBundle\Entity\TaskLink;

/**
 * Since 1.23: the queries that see archived tasks (the default ones hide them). Implemented by the Doctrine task
 * repository; imports and the provider sync use it when the configured repository implements it.
 */
interface ArchiveAwareTaskRepositoryInterface
{
    /**
     * Every task of the board, archived and completed ones included, by position.
     *
     * @return list<Task>
     */
    public function findByBoardIncludingArchived(TaskBoard $board): array;

    /**
     * The archived tasks of the board, most recently archived first.
     *
     * @return list<Task>
     */
    public function findArchivedByBoard(TaskBoard $board, int $limit): array;

    /**
     * The tasks of the board an import or a sync of scope `$scope` linked to these external ids (archived included),
     * the oldest link first.
     *
     * @param list<string> $externalIds
     *
     * @return array<string, array{0: Task, 1: TaskLink}>
     */
    public function findImportedByExternalIds(TaskBoard $board, string $scope, array $externalIds): array;
}
