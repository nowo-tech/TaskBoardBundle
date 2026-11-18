<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Question;

use RuntimeException;

/**
 * Since 2.23.0 (Beacon spec 242): the question changed since the version the writer read (a racing answer, a withdraw):
 * nothing was written; read the question again before trying. Not a lock conflict: a transaction-doctrine write unit
 * never replays it (it does not implement `LockConflictExceptionInterface`). The Task API answers it 409 `CONFLICT_002`.
 */
final class TaskQuestionConflictException extends RuntimeException
{
    public function __construct(public readonly int $currentVersion)
    {
        parent::__construct('The question changed: read it again');
    }
}
