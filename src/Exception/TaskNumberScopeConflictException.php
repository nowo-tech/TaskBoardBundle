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
    /**
     * Since 2.16.0 (Beacon spec 234): which task could not be numbered, and the scope **as the caller's own board asks
     * for it** — never the look-alike key the database stored, which belongs to someone else. A client that writes many
     * tasks at once (`POST /v2/task/bulk`) gets the offending id instead of bisecting its batch.
     *
     * There is no number to report: the conflict is raised before one is reserved. For a task being created, `$taskId`
     * is the id the task would have had (it was not created); for a task moved into another scope it is its stored id.
     */
    private function __construct(string $message, public readonly string $scopeKey, public readonly ?string $taskId)
    {
        parent::__construct($message);
    }

    public static function forScope(string $scopeKey, ?string $taskId = null): self
    {
        return new self(sprintf('The task-number counter of scope "%s" is shared with a look-alike scope: apply the TaskBoard 2.15.1 UPGRADING collation change.', $scopeKey), $scopeKey, $taskId);
    }

    /** The same conflict, naming the task (the caller's own, being written). */
    public function forTask(string $taskId): self
    {
        return new self($this->getMessage(), $this->scopeKey, $taskId);
    }
}
