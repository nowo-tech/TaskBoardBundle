<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Tree;

use Closure;
use Nowo\TaskBoardBundle\Entity\Task;

/**
 * Since 2.20.0 (Beacon spec 234, T004): the persistence seam of {@see TaskReparenter}. The bundle's implementation is
 * `Repository\DoctrineOrmTaskTreeStore` (on `database.entity_manager`, or the private manager); a test or a host may
 * double it. Plain PHP: a closure, ids, bundle entities and the bundle's value objects only.
 *
 * Implementations keep no state between calls (FrankenPHP worker mode, kernel reset false).
 */
interface TaskTreeStoreInterface
{
    /**
     * Runs `$work` in one transaction: committed when it returns, rolled back when it throws, with the task events it
     * dispatches held until the commit (dropped on a rollback). Opened inside a caller's transaction it nests (a
     * savepoint) and the caller owns the outcome.
     *
     * @template T
     *
     * @param Closure(): T $work
     *
     * @throws TaskReparentRefusedException `conflict` when the outermost transaction lost a lock to a concurrent write
     *
     * @return T
     */
    public function transactional(Closure $work): mixed;

    /**
     * The task's place as committed, its row locked until the transaction ends (`SELECT … FOR UPDATE`; a no-op where
     * the platform serialises writers, as SQLite does). Null when the task does not exist. Never the identity map.
     */
    public function lockedNode(string $taskId): ?TaskTreeNode;

    /**
     * The task of a node read by {@see self::lockedNode()}, as the database holds it now (a copy loaded before a
     * concurrent writer committed is refreshed).
     */
    public function task(TaskTreeNode $node): Task;

    /** Saves the task (and its history rows) inside the running transaction. */
    public function save(Task $task): void;

    /** `TASK_UPDATED` with the changed field `parent` (its previous value: the old parent), after the commit. */
    public function parentChanged(Task $task, object $actor, ?Task $previousParent): void;
}
