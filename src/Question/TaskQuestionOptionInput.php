<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Question;

/**
 * Since 2.23.0 (Beacon spec 242): an option of a question being asked (checked by {@see TaskQuestionManager::ask()}).
 */
final readonly class TaskQuestionOptionInput
{
    public function __construct(
        public string $label,
        public ?string $description = null,
        public bool $recommended = false,
    ) {
    }
}
