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
    public function __construct(public readonly int $currentRevision)
    {
        parent::__construct('TaskBoard: the task changed meanwhile; read it again.');
    }
}
