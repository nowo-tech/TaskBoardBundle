<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Dto;

/**
 * Form payload for creating or editing a task board.
 */
final class TaskBoardFormData
{
    public function __construct(
        public string $name = '',
        public string $slug = '',
        public ?string $description = null,
        /** Column set whose columns the new board gets instead of `board.default_columns` (since 1.11). */
        public ?string $columnSetId = null,
    ) {
    }
}
