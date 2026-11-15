<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Tree;

use Nowo\TaskBoardBundle\Entity\Task;
use Nowo\TaskBoardBundle\Enum\TaskChangeType;
use Nowo\TaskBoardBundle\Service\TaskChangeRecorder;
use Throwable;

use function count;

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
        // `max_subtask_depth` (null: no limit; the walks stay bounded by TaskTreeLimits::SAFETY_CAP).
        private TaskTreeLimits $limits = new TaskTreeLimits(),
    ) {
    }

    public function reparent(string $taskId, ?string $parentId, object $actor): TaskReparentResult
    {
        if ($parentId === $taskId) {
            throw TaskTreeRefusedException::self();
        }

        // @igor-ignore - The closure runs once, now, inside transactional(); the stateless store keeps no reference to it.
        return $this->store->transactional(function () use ($taskId, $parentId, $actor): TaskReparentResult {
            $node                   = $this->store->lockedNode($taskId) ?? throw TaskTreeRefusedException::notFound();
            [$parent, $parentDepth] = $parentId === null ? [null, -1] : $this->allowedParent($node, $parentId);
            if ($node->parentId === $parentId) {
                return new TaskReparentResult($node->id, $node->boardId, $node->parentId, $parentId, false);
            }
            $this->assertDepth($node, $parentDepth + 1);

            $task           = $this->store->task($node);
            $previousParent = $task->getParent();
            $newParent      = $parent instanceof TaskTreeNode ? $this->store->task($parent) : null;
            // @igor-ignore - A task entity loaded for this call (a row of one request), not a shared service.
            $task->setParent($newParent);
            try {
                // The ids differ (checked above), even when the titles do not (audit PX-L2): the row is always recorded.
                $this->changeRecorder->record($task, $actor, TaskChangeType::Parent, $previousParent?->getTitle(), $newParent?->getTitle(), $newParent instanceof Task ? 'task:' . $newParent->getId() : null);
                $this->store->save($task);
            } catch (Throwable $failure) {
                // The transaction rolls back: memory goes back to the old tree too (audit OG-1).
                // @igor-ignore - A task entity loaded for this call (a row of one request), not a shared service.
                $task->setParent($previousParent);

                throw $failure;
            }
            $this->store->parentChanged($task, $actor, $previousParent);

            return new TaskReparentResult($node->id, $node->boardId, $node->parentId, $parentId, true);
        });
    }

    /**
     * The new parent, locked, and its depth, when the rules allow it: the task's board, and not the task nor one of its
     * descendants (the walk up from the parent, each ancestor locked and read as committed, never meets the task). The
     * walk is iterative and stops at {@see TaskTreeLimits::SAFETY_CAP} levels (refused as too deep).
     *
     * @throws TaskTreeRefusedException
     *
     * @return array{0: TaskTreeNode, 1: int}
     */
    private function allowedParent(TaskTreeNode $node, string $parentId): array
    {
        $parent = $this->store->lockedNode($parentId) ?? throw TaskTreeRefusedException::notFound();
        if ($parent->boardId !== $node->boardId) {
            throw TaskTreeRefusedException::otherBoard();
        }
        $seen       = [$parent->id => true];
        $ancestorId = $parent->parentId;
        while ($ancestorId !== null) {
            // A loop already in the data (never written by the bundle) is refused as well: the walk never ends otherwise.
            if ($ancestorId === $node->id || isset($seen[$ancestorId])) {
                throw TaskTreeRefusedException::descendant();
            }
            if (count($seen) > TaskTreeLimits::SAFETY_CAP) {
                throw TaskTreeRefusedException::tooDeep();
            }
            $seen[$ancestorId] = true;
            $ancestorId        = $this->store->lockedNode($ancestorId)?->parentId;
        }

        return [$parent, count($seen) - 1];
    }

    /**
     * With `max_subtask_depth`: a move that takes the task deeper than it is must keep its whole subtree (its height
     * read under lock, the walk bounded by what is still allowed) within the limit. A move that takes it no deeper is
     * always allowed, so a tree saved deeper than the limit stays movable upwards.
     *
     * @throws TaskTreeRefusedException
     */
    private function assertDepth(TaskTreeNode $node, int $newDepth): void
    {
        if ($this->limits->maxSubtaskDepth === null || $newDepth <= $this->depthOf($node)) {
            return;
        }
        $allowedHeight = $this->limits->maxDepth() - $newDepth;
        if ($allowedHeight < 0 || $this->store->lockedHeight($node->id, $allowedHeight) > $allowedHeight) {
            throw TaskTreeRefusedException::tooDeep();
        }
    }

    /** The task's current depth, its ancestors locked; {@see TaskTreeLimits::UNBOUNDED} on a loop or past the cap. */
    private function depthOf(TaskTreeNode $node): int
    {
        $seen       = [$node->id => true];
        $ancestorId = $node->parentId;
        while ($ancestorId !== null) {
            if (isset($seen[$ancestorId]) || count($seen) > TaskTreeLimits::SAFETY_CAP) {
                return TaskTreeLimits::UNBOUNDED;
            }
            $seen[$ancestorId] = true;
            $ancestorId        = $this->store->lockedNode($ancestorId)?->parentId;
        }

        return count($seen) - 1;
    }
}
