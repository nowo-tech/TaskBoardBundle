<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Api\Value;

use Nowo\TaskBoardBundle\Entity\TaskBoard;
use Nowo\TaskBoardBundle\Entity\TaskBoardFolder;

/**
 * Since 2.18.0 (Beacon spec 234, FR-031): folders of a space, each with the boards in it the token may view, and the
 * tasks the token may count in each board.
 */
final readonly class TaskApiFolderList
{
    /**
     * @param list<TaskBoardFolder> $folders
     * @param array<string, list<TaskBoard>> $boards by folder id
     * @param array<string, int> $taskCounts by board id
     */
    public function __construct(
        public TaskApiSpace $space,
        public array $folders,
        public array $boards,
        public array $taskCounts,
    ) {
    }
}
