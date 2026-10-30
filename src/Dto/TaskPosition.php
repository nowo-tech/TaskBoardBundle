<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Dto;

use Nowo\TaskBoardBundle\Entity\Task;

/**
 * Since 1.20: where a task sits in an ordered sequence (the board, the list, or its parent's subtasks): its 1-based
 * index, the sequence length, its neighbours (null at the ends) and the group it is in (the column name).
 */
final readonly class TaskPosition
{
    public function __construct(
        public int $index,
        public int $total,
        public ?Task $previous,
        public ?Task $next,
        public ?string $group = null,
    ) {
    }
}
