<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Dto;

/**
 * Column workflow state for the task detail view.
 */
final readonly class TaskColumnNavigation
{
    public function __construct(
        public ?string $currentColumnName,
        public ?string $previousColumnName,
        public ?string $nextColumnName,
        public ?string $doneColumnName,
        public bool $canPrevious,
        public bool $canNext,
        public bool $canDone,
        public bool $isCompleted,
    ) {
    }
}
