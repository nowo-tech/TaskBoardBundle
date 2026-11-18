<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Question;

/**
 * Since 2.23.0 (Beacon spec 242): an option of a question as the host ports and events see it.
 */
final readonly class TaskQuestionOptionSnapshot
{
    public function __construct(
        public string $id,
        public int $position,
        public string $label,
        public ?string $description,
        public bool $recommended,
    ) {
    }
}
