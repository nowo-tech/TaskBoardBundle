<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Question;

/**
 * Since 2.23.0 (Beacon spec 242): an answer to give — option ids of the question and the *Other* text. Checked by
 * {@see TaskQuestionManager} before anything is written.
 */
final readonly class TaskQuestionAnswerInput
{
    /**
     * @param list<string> $optionIds
     */
    public function __construct(
        public array $optionIds = [],
        public ?string $otherText = null,
    ) {
    }
}
