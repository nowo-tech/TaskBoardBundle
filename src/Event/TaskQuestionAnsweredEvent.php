<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Event;

use Nowo\TaskBoardBundle\Question\TaskQuestionAnswerSnapshot;
use Nowo\TaskBoardBundle\Question\TaskQuestionSnapshot;
use Nowo\TaskBoardBundle\ValueObject\TaskBoardSnapshot;
use Nowo\TaskBoardBundle\ValueObject\TaskSnapshot;

/**
 * Since 2.23.0 (Beacon spec 242): {@see TaskBoardEvents::TASK_QUESTION_ANSWERED} — a question got an answer. `$superseded`
 * is the answer it replaced (null for a first answer); `$answer->via` is where it came from (`external`: through the
 * host's signed link, `$actorId` null).
 */
final class TaskQuestionAnsweredEvent extends AbstractTaskQuestionEvent
{
    public function __construct(
        TaskQuestionSnapshot $question,
        TaskSnapshot $task,
        TaskBoardSnapshot $board,
        ?string $actorId,
        public readonly TaskQuestionAnswerSnapshot $answer,
        public readonly ?TaskQuestionAnswerSnapshot $superseded,
    ) {
        parent::__construct($question, $task, $board, $actorId);
    }

    /** Where the answer came from: `web`, `app`, `api`, `mcp` or `external`. */
    public function getVia(): string
    {
        return $this->answer->via;
    }

    /** The recipient the answer was given for (an opaque subject id of the recipient resolver). */
    public function getAnswererSubjectId(): string
    {
        return $this->answer->subjectId;
    }
}
