<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Dto;

use Nowo\TaskBoardBundle\Question\TaskQuestionAnswerInput;

use function is_array;
use function is_string;
use function preg_match;

/**
 * Since 2.23.0 (Beacon spec 242): form payload of an answer — the option(s) picked (one id for a single-choice question,
 * a list for a multiple-choice one), the *Other* text and the question's version the answerer read.
 */
final class TaskQuestionAnswerFormData
{
    /**
     * @param list<string>|string|null $options
     */
    public function __construct(
        public string|array|null $options = null,
        public ?string $otherText = null,
        public ?string $version = null,
    ) {
    }

    public function toInput(): TaskQuestionAnswerInput
    {
        $ids = [];
        foreach (is_array($this->options) ? $this->options : [$this->options] as $id) {
            if (is_string($id) && $id !== '') {
                $ids[] = $id;
            }
        }

        return new TaskQuestionAnswerInput($ids, $this->otherText);
    }

    /** The version read, or null when the field is missing or not a positive integer. */
    public function versionNumber(): ?int
    {
        return $this->version !== null && preg_match('/^[1-9][0-9]{0,9}$/D', $this->version) === 1 && (int) $this->version <= 2147483647 ? (int) $this->version : null;
    }
}
