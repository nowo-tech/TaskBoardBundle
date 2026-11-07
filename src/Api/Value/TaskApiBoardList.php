<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Api\Value;

use Nowo\TaskBoardBundle\Entity\TaskBoard;

/**
 * Since 2.2.0 (spec 213): boards of a space with the tasks the token may count in each.
 */
final readonly class TaskApiBoardList
{
    /**
     * @param list<TaskBoard> $boards
     * @param array<string, int> $taskCounts by board id
     */
    public function __construct(
        public TaskApiSpace $space,
        public array $boards,
        public array $taskCounts,
    ) {
    }
}
