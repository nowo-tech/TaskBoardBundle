<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Question;

use DateTimeImmutable;
use Nowo\TaskBoardBundle\Question\Entity\TaskQuestion;
use Nowo\TaskBoardBundle\Question\Entity\TaskQuestionAnswer;
use Nowo\TaskBoardBundle\Question\Entity\TaskQuestionOption;
use Nowo\TaskBoardBundle\Question\Entity\TaskQuestionRecipient;
use Nowo\TaskBoardBundle\Support\ActorId;

use function array_map;

/**
 * Since 2.23.0 (Beacon spec 242): a question as the host ports, events and the answered-questions query see it — values
 * only (ids, texts, dates, the asker's id and name), copied when made; never an entity or a user object.
 */
final readonly class TaskQuestionSnapshot
{
    /**
     * @param list<TaskQuestionOptionSnapshot> $options
     * @param list<TaskQuestionRecipientSnapshot> $recipients
     * @param list<TaskQuestionAnswerSnapshot> $answers every answer, oldest first
     */
    public function __construct(
        public string $id,
        public string $taskId,
        public string $taskTitle,
        public string $boardId,
        public ?string $tenantKey,
        public string $text,
        public ?string $context,
        public TaskQuestionChoiceMode $choiceMode,
        public bool $allowOther,
        public TaskQuestionState $state,
        public array $options,
        public array $recipients,
        public ?DateTimeImmutable $dueOn,
        public ?string $askedById,
        public ?string $askedByName,
        public DateTimeImmutable $askedAt,
        public int $version,
        public ?TaskQuestionAnswerSnapshot $currentAnswer,
        public array $answers,
        public ?string $withdrawReason,
        public ?DateTimeImmutable $withdrawnAt,
        public ?string $publishedUrl,
        public ?string $publishedLabel,
        public ?string $withdrawnById = null,
        public ?string $withdrawnByName = null,
        public ?DateTimeImmutable $publishedAt = null,
    ) {
    }

    public static function of(TaskQuestion $question): self
    {
        $task    = $question->getTask();
        $board   = $task->getBoard();
        $current = $question->getCurrentAnswer();

        return new self(
            $question->getId(),
            $task->getId(),
            $task->getTitle(),
            $board->getId(),
            $board->getTenantKey(),
            $question->getText(),
            $question->getContext(),
            $question->getChoiceMode(),
            $question->allowsOther(),
            $question->getState(),
            array_map(static fn (TaskQuestionOption $option): TaskQuestionOptionSnapshot => new TaskQuestionOptionSnapshot($option->getId(), $option->getPosition(), $option->getLabel(), $option->getDescription(), $option->isRecommended()), $question->getOptions()),
            array_map(static fn (TaskQuestionRecipient $recipient): TaskQuestionRecipientSnapshot => new TaskQuestionRecipientSnapshot($recipient->getSubjectId(), $recipient->getLabel(), $recipient->isExternal()), $question->getRecipients()),
            $question->getDueOn(),
            ActorId::of($question->getAskedBy()),
            $question->getAskedByName(),
            $question->getAskedAt(),
            $question->getVersion(),
            $current instanceof TaskQuestionAnswer ? TaskQuestionAnswerSnapshot::of($current) : null,
            array_map(TaskQuestionAnswerSnapshot::of(...), $question->getAnswers()),
            $question->getWithdrawReason(),
            $question->getWithdrawnAt(),
            $question->getPublishedUrl(),
            $question->getPublishedLabel(),
            ActorId::of($question->getWithdrawnBy()),
            $question->getWithdrawnByName(),
            $question->getPublishedAt(),
        );
    }

    /** @return list<string> the recipients' subject ids */
    public function subjectIds(): array
    {
        return array_map(static fn (TaskQuestionRecipientSnapshot $recipient): string => $recipient->subjectId, $this->recipients);
    }
}
