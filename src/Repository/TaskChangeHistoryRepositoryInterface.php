<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Repository;

use Nowo\TaskBoardBundle\Entity\Task;
use Nowo\TaskBoardBundle\Entity\TaskChangeHistory;

/**
 * Persistence port for task change history entries.
 */
interface TaskChangeHistoryRepositoryInterface
{
    public function save(TaskChangeHistory $entry): void;

    /** @return list<TaskChangeHistory> */
    public function findByTask(Task $task): array;
}
