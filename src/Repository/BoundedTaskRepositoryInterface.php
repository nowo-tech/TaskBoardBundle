<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Repository;

use Nowo\TaskBoardBundle\Entity\Task;
use Nowo\TaskBoardBundle\Entity\TaskBoard;

/**
 * Since 1.21 (REQ-PERF-001): bounded board queries and a fetch-joined task detail. Implemented by the bundle's
 * Doctrine task repository; a host repository aliased to {@see TaskRepositoryInterface} without it keeps the
 * unbounded 1.20 behaviour.
 */
interface BoundedTaskRepositoryInterface
{
    /**
     * At most `$limit` tasks of the board in position order (the first cards of every column), with their members and
     * the members' users loaded (no query per card).
     *
     * @return list<Task>
     */
    public function findByBoardWithin(TaskBoard $board, bool $includeCompleted, int $limit): array;

    /**
     * The number of tasks of the board (completed ones too when `$includeCompleted`).
     */
    public function countByBoard(TaskBoard $board, bool $includeCompleted = false): int;

    /**
     * A task with the relations its page shows (board, column, parent, members and their users, links, documents,
     * dependencies and their tasks, change history and its users, time entries) in a fixed number of queries.
     */
    public function findForDetail(string $id): ?Task;
}
