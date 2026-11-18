<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Question;

use DateTimeImmutable;

/**
 * Since 2.23.0 (Beacon spec 242): a question to ask, as the UI form, the Task API or a host builds it. Nothing is trusted:
 * {@see TaskQuestionManager::ask()} checks every value before it writes anything.
 */
final readonly class TaskQuestionInput
{
    /**
     * @param list<TaskQuestionOptionInput> $options 0 or 2–4
     * @param list<string> $recipients subject ids of the recipient resolver's candidates
     */
    public function __construct(
        public string $text,
        public array $recipients,
        public array $options = [],
        public TaskQuestionChoiceMode $choiceMode = TaskQuestionChoiceMode::Single,
        public bool $allowOther = false,
        public ?string $context = null,
        public ?DateTimeImmutable $dueOn = null,
    ) {
    }
}
