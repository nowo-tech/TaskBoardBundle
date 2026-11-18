<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Question;

use RuntimeException;

/**
 * Since 2.23.0 (Beacon spec 242): a question write refused before anything was written. `reason` is one of the
 * constants; `field` names the input at fault (empty when none). The message names no id or person.
 */
final class TaskQuestionRefusedException extends RuntimeException
{
    /** The question's input is invalid (`field` says which). */
    public const INVALID_QUESTION = 'invalid_question';

    /** The answer's input is invalid (`field` says which). */
    public const INVALID_ANSWER = 'invalid_answer';

    /** The question is withdrawn (final). */
    public const WITHDRAWN = 'withdrawn';

    /** The actor may not do this (not a recipient, not the asker or a manager, no publisher). */
    public const FORBIDDEN = 'forbidden';

    /** The task may not get a question (archived) or holds `max_open_per_task` open ones. */
    public const TASK_CLOSED = 'task_closed';

    public function __construct(
        public readonly string $reason,
        public readonly string $field = '',
        string $message = '',
    ) {
        parent::__construct($message !== '' ? $message : 'The question write was refused: ' . $reason . ($field !== '' ? ' (' . $field . ')' : ''));
    }
}
