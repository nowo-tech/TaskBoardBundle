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
    ) {
    }
}
