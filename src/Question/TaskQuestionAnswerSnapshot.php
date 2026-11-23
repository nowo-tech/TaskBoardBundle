<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Question;

use DateTimeImmutable;
use Nowo\TaskBoardBundle\Question\Entity\TaskQuestionAnswer;
use Nowo\TaskBoardBundle\Question\Entity\TaskQuestionOption;
use Nowo\TaskBoardBundle\Support\ActorId;

use function array_map;

/**
 * Since 2.23.0 (Beacon spec 242): an answer as the host ports and events see it — values only, copied when made.
 */
final readonly class TaskQuestionAnswerSnapshot
{
    /**
     * @param list<string> $optionIds the options picked, in the question's order
     * @param list<string> $optionLabels their labels, in the same order
     */
    public function __construct(
        public string $id,
        public string $questionId,
        public int $number,
        public array $optionIds,
        public array $optionLabels,
        public ?string $otherText,
        public string $subjectId,
        public ?string $answeredById,
        public ?string $answeredByName,
        public string $via,
        public DateTimeImmutable $answeredAt,
        public ?DateTimeImmutable $supersededAt,
    ) {
    }

    public static function of(TaskQuestionAnswer $answer): self
    {
        $options = $answer->getSelectedOptions();

        return new self(
            $answer->getId(),
            $answer->getQuestion()->getId(),
            $answer->getSequence(),
            array_map(static fn (TaskQuestionOption $option): string => $option->getId(), $options),
            array_map(static fn (TaskQuestionOption $option): string => $option->getLabel(), $options),
            $answer->getOtherText(),
            $answer->getAnsweredBySubject(),
            ActorId::of($answer->getAnsweredBy()),
            $answer->getAnsweredByName(),
            $answer->getVia(),
            $answer->getAnsweredAt(),
            $answer->getSupersededAt(),
        );
    }
}
