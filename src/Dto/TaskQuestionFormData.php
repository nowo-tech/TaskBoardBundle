<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Dto;

use DateTimeImmutable;
use Nowo\TaskBoardBundle\Question\TaskQuestionChoiceMode;
use Nowo\TaskBoardBundle\Question\TaskQuestionInput;
use Nowo\TaskBoardBundle\Question\TaskQuestionOptionInput;

use function is_string;
use function trim;

/**
 * Since 2.23.0 (Beacon spec 242): form payload of *Ask a question* — four fixed option rows (empty rows are left out, so
 * the form needs no JavaScript), the recommended row, the recipients picked. {@see self::toInput()} hands it to the
 * manager, which checks everything.
 */
final class TaskQuestionFormData
{
    /** The option rows of the form. */
    public const OPTION_ROWS = 4;

    /**
     * @param list<string> $recipients
     */
    public function __construct(
        public string $text = '',
        public ?string $context = null,
        public string $option1 = '',
        public ?string $option1Description = null,
        public string $option2 = '',
        public ?string $option2Description = null,
        public string $option3 = '',
        public ?string $option3Description = null,
        public string $option4 = '',
        public ?string $option4Description = null,
        public ?string $recommended = null,
        public string $choiceMode = 'single',
        public bool $allowOther = false,
        public array $recipients = [],
        public ?DateTimeImmutable $dueOn = null,
    ) {
    }

    public function toInput(): TaskQuestionInput
    {
        $rows = [
            1 => [$this->option1, $this->option1Description],
            2 => [$this->option2, $this->option2Description],
            3 => [$this->option3, $this->option3Description],
            4 => [$this->option4, $this->option4Description],
        ];
        $options = [];
        foreach ($rows as $row => [$label, $description]) {
            if (trim($label) === '' && trim((string) $description) === '') {
                continue;
            }
            $options[] = new TaskQuestionOptionInput($label, $description, $this->recommended === (string) $row);
        }
        $recipients = [];
        foreach ($this->recipients as $recipient) {
            if (is_string($recipient)) {
                $recipients[] = $recipient;
            }
        }

        return new TaskQuestionInput(
            text: $this->text,
            recipients: $recipients,
            options: $options,
            choiceMode: TaskQuestionChoiceMode::tryFrom($this->choiceMode) ?? TaskQuestionChoiceMode::Single,
            allowOther: $this->allowOther,
            context: $this->context,
            dueOn: $this->dueOn,
        );
    }
}
