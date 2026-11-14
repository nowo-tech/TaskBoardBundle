<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Tree;

use Nowo\TaskBoardBundle\Entity\Task;
use Nowo\TaskBoardBundle\Enum\TaskChangeType;
use Nowo\TaskBoardBundle\Service\TaskChangeRecorder;

/**
 * Since 2.20.0 (Beacon spec 234, T004): the domain service behind {@see TaskReparenterInterface}. Framework-free: it reads
 * and writes through {@see TaskTreeStoreInterface} and records through {@see TaskChangeRecorder}; stateless (worker mode).
 *
 * Concurrency: everything runs in one transaction. The task's row is locked first, then the new parent's and each of its
 * ancestors' up to the root, one at a time, each read as committed after its lock is granted. The cycle check walks those
 * locked rows only, so any concurrent change of a parent on that path either committed before it was read (and is seen)
 * or waits for this commit (and then checks against this one). Two crossing changes (A under B while B goes under A) lock
 * the same rows in opposite orders: the database aborts one (a deadlock, or a lock wait that times out), which answers
 * `conflict` and writes nothing. Strict two-phase locking over the rows of the path makes the outcome that of one change
 * after the other, and each one alone keeps the tree acyclic.
 */
final readonly class TaskReparenter implements TaskReparenterInterface
{
    public function __construct(
        private TaskTreeStoreInterface $store,
        private TaskChangeRecorder $changeRecorder,
    ) {
    }

    public function reparent(string $taskId, ?string $parentId, object $actor): TaskReparentResult
    {
        if ($parentId === $taskId) {
            throw TaskReparentRefusedException::self();
        }

        // @igor-ignore - The closure runs once, now, inside transactional(); the stateless store keeps no reference to it.
        return $this->store->transactional(function () use ($taskId, $parentId, $actor): TaskReparentResult {
            $node   = $this->store->lockedNode($taskId) ?? throw TaskReparentRefusedException::notFound();
            $parent = $parentId === null ? null : $this->allowedParent($node, $parentId);
            if ($node->parentId === $parentId) {
                return new TaskReparentResult($node->id, $node->boardId, $node->parentId, $parentId, false);
            }

            $task           = $this->store->task($node);
            $previousParent = $task->getParent();
            $newParent      = $parent instanceof TaskTreeNode ? $this->store->task($parent) : null;
            // @igor-ignore - A task entity loaded for this call (a row of one request), not a shared service.
            $task->setParent($newParent);
            $this->changeRecorder->record($task, $actor, TaskChangeType::Parent, $previousParent?->getTitle(), $newParent?->getTitle(), $newParent instanceof Task ? 'task:' . $newParent->getId() : null);
            $this->store->save($task);
            $this->store->parentChanged($task, $actor, $previousParent);

            return new TaskReparentResult($node->id, $node->boardId, $node->parentId, $parentId, true);
        });
    }

    /**
     * The new parent, locked, when the rules allow it: the task's board, and not the task nor one of its descendants
     * (the walk up from the parent, each ancestor locked and read as committed, never meets the task).
     *
     * @throws TaskReparentRefusedException
     */
    private function allowedParent(TaskTreeNode $node, string $parentId): TaskTreeNode
    {
        $parent = $this->store->lockedNode($parentId) ?? throw TaskReparentRefusedException::notFound();
        if ($parent->boardId !== $node->boardId) {
            throw TaskReparentRefusedException::otherBoard();
        }
        $seen       = [$parent->id => true];
        $ancestorId = $parent->parentId;
        while ($ancestorId !== null) {
            // A loop already in the data (never written by the bundle) is refused as well: the walk never ends otherwise.
            if ($ancestorId === $node->id || isset($seen[$ancestorId])) {
                throw TaskReparentRefusedException::descendant();
            }
            $seen[$ancestorId] = true;
            $ancestorId        = $this->store->lockedNode($ancestorId)?->parentId;
        }

        return $parent;
    }
}
