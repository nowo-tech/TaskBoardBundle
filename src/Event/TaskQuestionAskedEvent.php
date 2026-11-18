<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Event;

/**
 * Since 2.23.0 (Beacon spec 242): {@see TaskBoardEvents::TASK_QUESTION_ASKED} — a question was asked on a task (its
 * recipients are `$question->recipients`).
 */
final class TaskQuestionAskedEvent extends AbstractTaskQuestionEvent
{
}
