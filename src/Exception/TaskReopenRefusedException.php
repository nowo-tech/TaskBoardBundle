<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Exception;

use Nowo\TaskBoardBundle\Sync\Hook\LimitRefusal;
use RuntimeException;

/**
 * Since 1.29.0 (audit HN-M1): moving a completed task out of the last column would make it active again, and the host's
 * limit refused. Nothing was written.
 */
final class TaskReopenRefusedException extends RuntimeException
{
    public function __construct(public readonly LimitRefusal $refusal)
    {
        parent::__construct('The limit of active tasks was reached.');
    }
}
