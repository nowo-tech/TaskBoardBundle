<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Event;

/**
 * Since 2.23.0 (Beacon spec 242): {@see TaskBoardEvents::TASK_QUESTION_WITHDRAWN} — the asker or a board manager
 * withdrew the question (the reason is `$question->withdrawReason`).
 */
final class TaskQuestionWithdrawnEvent extends AbstractTaskQuestionEvent
{
    /** Why it was withdrawn (plain text, 1–500 characters). */
    public function getReason(): string
    {
        return (string) $this->question->withdrawReason;
    }
}
