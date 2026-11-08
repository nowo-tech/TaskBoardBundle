<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Move;

use Doctrine\DBAL\Exception\DeadlockException;
use Doctrine\DBAL\Exception\LockWaitTimeoutException;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Nowo\TaskBoardBundle\Entity\BoardColumn;
use Nowo\TaskBoardBundle\Entity\Task;
use Nowo\TaskBoardBundle\Entity\TaskBoard;
use Nowo\TaskBoardBundle\Enum\TaskChangeType;
use Nowo\TaskBoardBundle\Event\TaskBoardChangedEvent;
use Nowo\TaskBoardBundle\Event\TaskBoardEvents;
use Nowo\TaskBoardBundle\Event\TaskBoardQuotaCheckEvent;
use Nowo\TaskBoardBundle\Exception\TaskCopyRefusedException;
use Nowo\TaskBoardBundle\Repository\TaskRepositoryInterface;
use Nowo\TaskBoardBundle\Service\TaskBoardQuota;
use Nowo\TaskBoardBundle\Service\TaskChangeRecorder;
use Nowo\TaskBoardBundle\Sync\Entity\TaskBoardConnection;
use Nowo\TaskBoardBundle\Sync\Entity\TaskSyncItem;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;
use Throwable;

use function in_array;
use function max;

/**
 * Since 2.3.0 (spec 217, FR-006): moves a task and its subtasks to another board, in one transaction, exactly as its
 * {@see TaskMovePlan} said. **Tracked time is never deleted:** manual entries stay on their task and take the new board as
 * their snapshot; TimeTrack's rows point at the task by id and the host refreshes their snapshots on TASK_BOARD_CHANGED.
 *
 * Refused before anything is written: a plan whose fingerprint changed (`stale`), a provider run in progress on either
 * board (`sync_running`), the host's quota on the open tasks that arrive ({@see TaskBoardQuotaCheckEvent::ACTION_TASK_MOVE},
 * `quota`). A synced task is detached for good: its sync item becomes a tombstone (the provider data stays on it), so a
 * later run neither updates nor recreates it. History gets a "moved to another board" row per task; TASK_BOARD_CHANGED is
 * dispatched after the commit, and TASK_MOVED is not.
 */
final readonly class TaskMover
{
    public function __construct(
        private TaskMovePlanner $planner,
        private EntityManagerInterface $entityManager,
        private TaskRepositoryInterface $taskRepository,
        private TaskChangeRecorder $changeRecorder,
        private TaskBoardQuota $quota = new TaskBoardQuota(),
        private ?EventDispatcherInterface $eventDispatcher = null,
        private bool $syncEnabled = false,
    ) {
    }

    /**
     * @param array<string, string> $columnMap the map the plan was made with
     *
     * @throws TaskCopyRefusedException before anything is written
     */
    public function move(Task $task, TaskBoard $target, array $columnMap, string $fingerprint, object $actor): TaskMovePlan
    {
        $plan = $this->planner->plan($task, $target, $columnMap, $actor);
        if ($plan->fingerprint !== $fingerprint) {
            throw new TaskCopyRefusedException('stale');
        }
        if ($this->runInProgress($plan->sourceBoard) || $this->runInProgress($target)) {
            throw new TaskCopyRefusedException('sync_running');
        }
        $refusal = $plan->openTasks > 0 ? $this->quota->refusal(TaskBoardQuotaCheckEvent::ACTION_TASK_MOVE, $target->getTenantKey(), $target, $plan->openTasks, $actor) : null;
        if ($refusal !== null) {
            throw new TaskCopyRefusedException('quota', [], $refusal);
        }

        // Audit IC-L2: not wrapInTransaction(), which closes the entity manager on any exception: a refusal found inside
        // rolls back and leaves it usable, so the page can show the new preview.
        $connection = $this->entityManager->getConnection();
        $connection->beginTransaction();
        try {
            // Audit IC-L2r: every task row of the tree, level by level, before the re-read. A concurrent write that refers to
            // any of them (a dependency, a member, a subtask) then waits for this commit instead of slipping in after the check.
            $this->lockTree($task);
            if ($this->planner->snapshot($task) !== $plan->snapshot) {
                throw new TaskCopyRefusedException('stale');
            }
            $this->apply($plan, $task, $target, $columnMap, $actor);
            $connection->commit();
        } catch (Throwable $failure) {
            if ($connection->isTransactionActive()) {
                $connection->rollBack();
            }
            // Audit IC-I9: a deadlock or a lock wait that timed out against a concurrent write of the same tree is "changed
            // since", as a stale plan is: nothing was written, and the user sees the new preview.
            if ($failure instanceof DeadlockException || $failure instanceof LockWaitTimeoutException) {
                throw new TaskCopyRefusedException('stale');
            }

            throw $failure;
        }

        $this->eventDispatcher?->dispatch(new TaskBoardChangedEvent($task, $actor, $plan->sourceBoard->getId(), $target->getId(), $plan->taskIds), TaskBoardEvents::TASK_BOARD_CHANGED);

        return $plan;
    }

    /**
     * The writes of a move, inside its transaction, exactly as the plan said.
     *
     * @param array<string, string> $columnMap
     */
    private function apply(TaskMovePlan $plan, Task $task, TaskBoard $target, array $columnMap, object $actor): void
    {
        $source    = $plan->sourceBoard;
        $positions = $this->nextPositions($target);
        $tree      = TaskMovePlanner::subtree($task);
        $task->setParent(null);
        foreach ($tree as $node) {
            foreach ($node->getMembers() as $member) {
                if (in_array($member->getId(), $plan->memberIds, true)) {
                    $this->changeRecorder->recordMember($node, $actor, TaskChangeType::MemberRemoved, $member->getUser(), $member->getMemberRole()->value);
                    $node->removeMember($member);
                }
            }
            foreach ([...$node->getOutgoingDependencies(), ...$node->getIncomingDependencies()] as $dependency) {
                if (in_array($dependency->getId(), $plan->dependencyIds, true)) {
                    $outgoing = $dependency->getSourceTask() === $node;
                    $other    = $outgoing ? $dependency->getTargetTask() : $dependency->getSourceTask();
                    $node->removeDependency($dependency);
                    $this->changeRecorder->record($node, $actor, TaskChangeType::DependencyRemoved, $other->getTitle(), null, $dependency->getDependencyType()->relation(!$outgoing));
                    $this->changeRecorder->record($other, $actor, TaskChangeType::DependencyRemoved, $node->getTitle(), null, $dependency->getDependencyType()->relation($outgoing));
                }
            }
            $column = TaskMovePlanner::targetColumn($node, $target, $columnMap);
            $key    = $column?->getId() ?? '';
            // Audit IC-I8: the sync's "Fields" document holds the imported values as text: hidden on the target too.
            foreach ($node->getDocuments() as $document) {
                $document->rememberOrigin($source->getId());
            }
            $node->changeBoard($target, $column, $positions[$key] ?? 0);
            $positions[$key] = ($positions[$key] ?? 0) + 1;
            $this->changeRecorder->record($node, $actor, TaskChangeType::BoardMoved, $source->getName(), $target->getName());
        }
        // Audit IC-I5: whenever the sync tables exist, sync on or off now. IC-I4: the tombstone keeps the task as
        // detached, so the panel still shows its provider data, read-only, and the people tools still erase it.
        if ($this->planner->syncMapped()) {
            foreach ($this->entityManager->getRepository(TaskSyncItem::class)->findBy(['task' => $plan->taskIds]) as $item) {
                $item->detach();
            }
        } else {
            // Audit IC-I5 (delta): sync off, so not mapped, but the rows of an earlier sync may be there.
            $this->planner->detachUnmappedItems($plan->taskIds);
        }
        $this->taskRepository->save($task);
    }

    /**
     * Audit IC-L2r: `SELECT … FOR UPDATE` on the task and every descendant, a level at a time (no-op on SQLite).
     */
    private function lockTree(Task $task): void
    {
        $level = [$task->getId()];
        $this->entityManager->createQueryBuilder()->select('t.id')->from(Task::class, 't')->where('t.id IN (:ids)')->setParameter('ids', $level)
            ->getQuery()->setLockMode(LockMode::PESSIMISTIC_WRITE)->getSingleColumnResult();
        while ($level !== []) {
            /** @var list<string> $level */
            $level = $this->entityManager->createQueryBuilder()->select('t.id')->from(Task::class, 't')->where('IDENTITY(t.parent) IN (:ids)')->setParameter('ids', $level)
                ->getQuery()->setLockMode(LockMode::PESSIMISTIC_WRITE)->getSingleColumnResult();
        }
    }

    /**
     * The next position in each column of the target (key: column id, '' for none).
     *
     * @return array<string, int>
     */
    private function nextPositions(TaskBoard $target): array
    {
        $positions = [];
        foreach ($this->taskRepository->findByBoard($target, true) as $existing) {
            $key             = $existing->getColumn() instanceof BoardColumn ? $existing->getColumn()->getId() : '';
            $positions[$key] = max($positions[$key] ?? 0, $existing->getPosition() + 1);
        }

        return $positions;
    }

    private function runInProgress(TaskBoard $board): bool
    {
        if (!$this->syncEnabled) {
            return false;
        }
        $connection = $this->entityManager->getRepository(TaskBoardConnection::class)->findOneBy(['board' => $board]);

        return $connection instanceof TaskBoardConnection && $connection->getRunningRunId() !== null;
    }
}
