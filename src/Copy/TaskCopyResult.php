<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Copy;

use Nowo\TaskBoardBundle\Entity\Task;

/**
 * Since 2.3.0 (specs 215, 217): the tasks a {@see TaskCopier} wrote.
 */
final readonly class TaskCopyResult
{
    /**
     * @param list<Task> $tasks the copy first, then its copied subtasks (pre-order)
     * @param list<string> $membersNotCopied labels of the members left out (no longer assignable on the target)
     */
    public function __construct(
        public Task $task,
        public array $tasks,
        public array $membersNotCopied,
    ) {
    }
}
