<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Repository;

use Nowo\TaskBoardBundle\Entity\Task;
use Nowo\TaskBoardBundle\Entity\TaskBoard;

interface TaskRepositoryInterface
{
    public function save(Task $task): void;

    public function findById(string $id): ?Task;

    /**
     * @return list<Task>
     */
    public function findByBoard(TaskBoard $board, bool $includeCompleted = false): array;

    /**
     * @return list<Task>
     */
    public function findTrackableForUser(string $userId, ?string $search, int $limit, int $offset): array;
}
