<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Api\Value;

use Nowo\TaskBoardBundle\Entity\BoardColumn;
use Nowo\TaskBoardBundle\Entity\TaskBoard;
use Nowo\TaskBoardBundle\Template\Entity\TaskTemplate;

/**
 * Since 2.16.0 (Beacon spec 234, FR-061): the task templates a board offers a token, with each one's default column on
 * that board (null: none, or it no longer exists).
 */
final readonly class TaskApiTemplates
{
    /**
     * @param list<TaskTemplate> $templates
     * @param array<string, BoardColumn|null> $columns by template id
     */
    public function __construct(
        public TaskBoard $board,
        public array $templates,
        public array $columns,
    ) {
    }
}
