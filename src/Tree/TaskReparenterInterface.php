<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Tree;

use Nowo\TaskBoardBundle\Move\TaskMoverInterface;

/**
 * Since 2.20.0 (Beacon spec 234, T004): the port that changes a task's parent, or detaches it (`$parentId` null), with
 * the rules every caller shares: the same board only, never the task itself nor one of its descendants (checked on the
 * rows as committed, locked, inside one transaction, so two concurrent changes cannot close a cycle together). One
 * history row (`TaskChangeType::Parent`, with the host's write provenance), a new revision, and `TASK_UPDATED` (changed
 * field `parent`) after the commit. The Task API (`PUT /v2/task/{id}` with `parent`, MCP `task_update`) calls it; a host's
 * application layer depends on it and may double or decorate it. Plain PHP: ids in, a value object out.
 *
 * Depth: with `max_subtask_depth` a move that takes the task deeper keeps its whole subtree within the limit
 * (`too_deep`); a move that takes it no deeper is always allowed. Without it, no limit (every walk stays bounded by
 * {@see TaskTreeLimits::SAFETY_CAP} levels).
 *
 * Transactions: the port opens its own, or joins the caller's (no savepoint). Inside your own transaction, wrap it in
 * `TaskEventDeferral::defer()` (public service) so `TASK_UPDATED` waits for your commit and is dropped on your rollback:
 * `$deferral->defer(function () { $connection->beginTransaction(); …; $reparenter->reparent(…); $connection->commit(); })`.
 * Without it the event is dispatched when the port returns (audit PX-L3).
 *
 * No access check (as {@see TaskMoverInterface}): before calling it the host checks what the Task API
 * checks: `TaskBoardResourceAccessChecker::canEditTask()` on the task and on the new parent (in Beacon:
 * `project.tasks.manage` on project boards, `organization.tasks.edit` on organization boards), and that both belong to the current tenant.
 */
interface TaskReparenterInterface
{
    /**
     * @param object $actor the user the history row names (your own user object)
     *
     * @throws TaskTreeRefusedException before anything is written
     */
    public function reparent(string $taskId, ?string $parentId, object $actor): TaskReparentResult;
}
