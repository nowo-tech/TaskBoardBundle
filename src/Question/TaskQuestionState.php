<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Question;

/**
 * Since 2.23.0 (Beacon spec 242): the state of a question. `open` until a first answer, `answered` (a later answer
 * supersedes the current one), `withdrawn` (final, with a reason). A due date never changes the state.
 */
enum TaskQuestionState: string
{
    case Open      = 'open';
    case Answered  = 'answered';
    case Withdrawn = 'withdrawn';
}
