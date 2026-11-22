<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Exception;

use Nowo\TaskBoardBundle\Service\TaskCommandsInterface;
use RuntimeException;

/**
 * Since 2.29.0 (REQ-RUNTIME-003 rule 4): a {@see TaskCommandsInterface} write with an expected revision found the task
 * changed meanwhile (the HTTP answer is 409 CONFLICT). Nothing was written; read the task again (`$currentRevision`).
 */
final class TaskChangedException extends RuntimeException
{
    /**
     * Since 2.30.2 (audit ZD-I5): the house conflict code a host answers with (409, `X-Conflict-Code: CONFLICT_001`):
     * nothing was written, a retry after reading the task again is safe.
     */
    public const CODE = 'CONFLICT_001';

    public function __construct(public readonly int $currentRevision)
    {
        parent::__construct('TaskBoard: the task changed meanwhile; read it again.');
    }
}
