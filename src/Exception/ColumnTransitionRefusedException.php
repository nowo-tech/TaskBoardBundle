<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Exception;

use Nowo\TaskBoardBundle\ValueObject\ColumnTransition;
use Nowo\TaskBoardBundle\ValueObject\ColumnTransitionDecision;
use RuntimeException;

/**
 * Since 2.16.0 (spec 234): the host's column transition policy refused a task entering a column. Nothing was written.
 * The Task API answers 403 with the decision's code as `ECODE` and its message; the board UI shows the message.
 */
final class ColumnTransitionRefusedException extends RuntimeException
{
    public function __construct(public readonly ColumnTransition $transition, public readonly ColumnTransitionDecision $decision)
    {
        parent::__construct('The column transition policy refused this move (' . $decision->code . ').');
    }
}
