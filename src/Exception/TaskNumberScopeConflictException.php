<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Exception;

use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

use function sprintf;

/**
 * Since 2.15.1 (REQ-SEC-006, audit OM TB-L2): the task-number counter the database finds for a scope belongs to a
 * look-alike scope (`Acme` for `acme`) because `scope_key` still folds (before the UPGRADING ALTER). The task is not
 * created rather than sharing that scope's sequence; the web pages and the API answer 409.
 */
final class TaskNumberScopeConflictException extends ConflictHttpException
{
    public static function forScope(string $scopeKey): self
    {
        return new self(sprintf('The task-number counter of scope "%s" is shared with a look-alike scope: apply the TaskBoard 2.15.1 UPGRADING collation change.', $scopeKey));
    }
}
