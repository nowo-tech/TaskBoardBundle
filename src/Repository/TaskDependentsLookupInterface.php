<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Repository;

use Nowo\TaskBoardBundle\Entity\Task;
use Nowo\TaskBoardBundle\Service\TaskDependencyManager;

/**
 * Since 2.16.0 (spec 234): the tasks that wait on a task (the reverse of `blocked_by`): every task `X` with "`X`
 * blocked by `$task`" or "`$task` blocks `X`". Implemented by {@see DoctrineOrmTaskDependencyRepository}; ask it through
 * {@see TaskDependencyManager::dependentsOf()}.
 */
interface TaskDependentsLookupInterface
{
    /**
     * @return list<Task> each once, in no particular order
     */
    public function dependentsOf(Task $task): array;
}
