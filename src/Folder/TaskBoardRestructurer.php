<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Folder;

use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Nowo\TaskBoardBundle\Copy\TaskColumnRule;
use Nowo\TaskBoardBundle\Doctrine\RecoveringFlusher;
use Nowo\TaskBoardBundle\Entity\BoardColumn;
use Nowo\TaskBoardBundle\Entity\Task;
use Nowo\TaskBoardBundle\Entity\TaskBoard;
use Nowo\TaskBoardBundle\Entity\TaskBoardFolder;
use Nowo\TaskBoardBundle\Enum\TaskChangeType;
use Nowo\TaskBoardBundle\Event\TaskBoardChangedEvent;
use Nowo\TaskBoardBundle\Event\TaskBoardEvents;
use Nowo\TaskBoardBundle\Event\TaskBoardQuotaCheckEvent;
use Nowo\TaskBoardBundle\Move\TaskMovePlanner;
use Nowo\TaskBoardBundle\Move\TaskMover;
use Nowo\TaskBoardBundle\Port\Snapshots;
use Nowo\TaskBoardBundle\Repository\TaskBoardRepositoryInterface;
use Nowo\TaskBoardBundle\Repository\TaskRepositoryInterface;
use Nowo\TaskBoardBundle\Service\TaskBoardQuota;
use Nowo\TaskBoardBundle\Service\TaskChangeRecorder;
use Nowo\TaskBoardBundle\Service\TaskEventDeferral;
use Nowo\TaskBoardBundle\Service\TaskMemberAssigner;
use Nowo\TaskBoardBundle\Sync\Entity\TaskBoardConnection;
use Nowo\TaskBoardBundle\Sync\Entity\TaskSyncItem;
use Nowo\TaskBoardBundle\Sync\Status\StatusColumns;
use Throwable;

use function array_map;
use function in_array;
use function is_string;
use function max;

/**
 * Since 2.17.0 (Beacon spec 234, FR-031 / FR-045): {@see TaskBoardRestructureInterface} — folders through
 * {@see TaskBoardFolderManager}, and tasks moved between the boards of one tenant.
 *
 * A task move is one transaction: the rows of every moved tree are locked first, then each task changes board in place
 * (same row, so its id, number, comments, time entries, tags, documents, links and history stay), a subtask asked for
 * becomes a top-level task of the target, a member the target cannot assign is removed and a dependency the target cannot
 * keep ({@see TaskBoardDependencyScope}) is removed, each with a history row, and a synced task is detached from its
 * provider for good (as {@see TaskMover} does). `TASK_BOARD_CHANGED` is dispatched once per
 * moved tree after the commit. On any failure the transaction rolls back; once the in-memory entities were changed the
 * entity manager is closed (a later flush cannot write half a move) and reset through the registry, so a worker with
 * kernel reset off never keeps a closed manager.
 *
 * Stateless (REQ-RUNTIME-002).
 */
final readonly class TaskBoardRestructurer implements TaskBoardRestructureInterface
{
    public function __construct(
        private TaskBoardFolderManager $folders,
        private TaskBoardRestructurePolicyInterface $policy,
        private EntityManagerInterface $entityManager,
        private TaskBoardRepositoryInterface $boards,
        private TaskRepositoryInterface $tasks,
        private TaskChangeRecorder $changeRecorder,
        private TaskMemberAssigner $members,
        private TaskMovePlanner $planner,
        private TaskEventDeferral $events,
        private TaskBoardDependencyScope $dependencyScope = new TaskBoardDependencyScope(),
        private TaskBoardQuota $quota = new TaskBoardQuota(),
        private ?ManagerRegistry $managerRegistry = null,
        private bool $syncEnabled = false,
    ) {
    }

    public function folders(?string $tenantKey): array
    {
        return array_map($this->snapshot(...), $this->folders->folders($tenantKey));
    }

    public function folder(string $folderId): ?TaskBoardFolderSnapshot
    {
        $folder = $this->folders->find($folderId);

        return $folder instanceof TaskBoardFolder ? $this->snapshot($folder) : null;
    }

    public function createFolder(object $actor, ?string $tenantKey, string $name, ?string $description = null, ?int $position = null): TaskBoardFolderSnapshot
    {
        return $this->snapshot($this->folders->create($actor, $tenantKey, $name, $description, $position));
    }

    public function updateFolder(object $actor, string $folderId, string $name, ?string $description, int $position): TaskBoardFolderSnapshot
    {
        return $this->snapshot($this->folders->update($actor, $this->requireFolder($folderId), $name, $description, $position));
    }

    public function deleteFolder(object $actor, string $folderId): void
    {
        $this->folders->delete($actor, $this->requireFolder($folderId));
    }

    public function moveBoardToFolder(object $actor, string $boardId, ?string $folderId): void
    {
        $board = $this->requireBoard($boardId);
        $this->folders->moveBoard($actor, $board, $folderId === null ? null : $this->requireFolder($folderId));
    }

    public function moveTasks(object $actor, array $taskIds, string $targetBoardId, array $columnMap = []): TaskBoardMoveResult
    {
        $target = $this->requireBoard($targetBoardId);
        $roots  = $this->roots($target, $taskIds);
        if ($target->isArchived()) {
            throw new TaskBoardRestructureRefusedException(TaskBoardRestructureRefusedException::BOARD_ARCHIVED);
        }
        $map = $this->resolveMap($target, $columnMap);

        $moving    = [];
        $unchanged = [];
        $sources   = [$target->getId() => $target];
        foreach ($roots as $root) {
            if ($root->getBoard()->getId() === $target->getId() && !$root->getParent() instanceof Task) {
                $unchanged[] = $root->getId();

                continue;
            }
            $moving[]                            = $root;
            $sources[$root->getBoard()->getId()] = $root->getBoard();
        }
        foreach ($sources as $board) {
            if (!$this->policy->mayRestructureBoard($actor, Snapshots::board($board))) {
                throw new TaskBoardRestructureRefusedException(TaskBoardRestructureRefusedException::NOT_ALLOWED);
            }
            if ($this->runInProgress($board)) {
                throw new TaskBoardRestructureRefusedException(TaskBoardRestructureRefusedException::SYNC_RUNNING);
            }
        }
        if ($moving === []) {
            return new TaskBoardMoveResult($target->getId(), [], $unchanged, [], [], []);
        }
        $open = 0;
        foreach ($moving as $root) {
            foreach (TaskMovePlanner::subtree($root) as $node) {
                $open += (int) ($node->getBoard()->getId() !== $target->getId() && !$node->isCompleted() && !$node->isArchived());
            }
        }
        $refusal = $open > 0 ? $this->quota->refusal(TaskBoardQuotaCheckEvent::ACTION_TASK_MOVE, $target->getTenantKey(), $target, $open, $actor) : null;
        if ($refusal !== null) {
            throw new TaskBoardRestructureRefusedException(TaskBoardRestructureRefusedException::QUOTA, [], $refusal);
        }

        $connection = $this->entityManager->getConnection();
        $connection->beginTransaction();
        $this->events->begin();
        $applying = false;
        try {
            $this->lock($moving);
            $applying = true;
            $result   = $this->apply($moving, $target, $map, $actor, $unchanged);
            RecoveringFlusher::flush($this->entityManager, $this->managerRegistry);
            $connection->commit();
        } catch (Throwable $failure) {
            if ($connection->isTransactionActive()) {
                $connection->rollBack();
            }
            $this->events->rollBack();
            if ($applying && $this->entityManager->isOpen()) {
                $this->entityManager->close();
            }
            RecoveringFlusher::resetIfClosed($this->entityManager, $this->managerRegistry);

            throw $failure;
        }
        $this->events->commit(false);

        return $result;
    }

    /**
     * @param list<Task> $roots
     * @param array<string, BoardColumn> $map
     * @param list<string> $unchanged
     */
    private function apply(array $roots, TaskBoard $target, array $map, object $actor, array $unchanged): TaskBoardMoveResult
    {
        $positions = $this->nextPositions($target);
        $moved     = [];
        $columns   = [];
        $deps      = [];
        $members   = [];
        $events    = [];
        $parents   = [];
        foreach ($roots as $root) {
            $tree = TaskMovePlanner::subtree($root);
            // Before the root becomes top-level: each task's parent as it was (the host archives the old story).
            foreach ($tree as $node) {
                $parents[$node->getId()] = $node->getParent()?->getId();
            }
            $ids    = array_map(static fn (Task $node): string => $node->getId(), $tree);
            $source = $root->getBoard();
            $root->setParent(null);
            foreach ($tree as $node) {
                foreach ($node->getMembers() as $member) {
                    if (!$this->members->canBeAssigned($target, $member->getUser())) {
                        $members[] = $member->getId();
                        $this->changeRecorder->recordMember($node, $actor, TaskChangeType::MemberRemoved, $member->getUser(), $member->getMemberRole()->value);
                        $node->removeMember($member);
                    }
                }
                foreach ([...$node->getOutgoingDependencies(), ...$node->getIncomingDependencies()] as $dependency) {
                    $outgoing = $dependency->getSourceTask() === $node;
                    $other    = $outgoing ? $dependency->getTargetTask() : $dependency->getSourceTask();
                    if (in_array($other->getId(), $ids, true) || in_array($dependency->getId(), $deps, true) || $this->dependencyScope->allows($target, $other->getBoard())) {
                        continue;
                    }
                    $deps[] = $dependency->getId();
                    $node->removeDependency($dependency);
                    $this->changeRecorder->record($node, $actor, TaskChangeType::DependencyRemoved, $other->getTitle(), null, $dependency->getDependencyType()->relation(!$outgoing));
                    $this->changeRecorder->record($other, $actor, TaskChangeType::DependencyRemoved, $node->getTitle(), null, $dependency->getDependencyType()->relation($outgoing));
                }
                $from   = $node->getBoard();
                $column = $this->targetColumn($node, $target, $map);
                $key    = $column?->getId() ?? '';
                foreach ($node->getDocuments() as $document) {
                    $document->rememberOrigin($from->getId());
                }
                $node->changeBoard($target, $column, $positions[$key] ?? 0);
                $positions[$key] = ($positions[$key] ?? 0) + 1;
                if ($from->getId() !== $target->getId()) {
                    $this->changeRecorder->record($node, $actor, TaskChangeType::BoardMoved, $from->getName(), $target->getName(), $parents[$node->getId()] === null ? null : 'parent:' . $parents[$node->getId()]);
                }
                $moved[]                 = $node->getId();
                $columns[$node->getId()] = $key;
            }
            $events[] = [$root, $source->getId(), $ids];
        }
        if ($this->planner->syncMapped()) {
            foreach ($this->entityManager->getRepository(TaskSyncItem::class)->findBy(['task' => $moved]) as $item) {
                $item->detach();
            }
        } else {
            $this->planner->detachUnmappedItems($moved);
        }
        foreach ($events as [$root, $sourceId, $ids]) {
            // Held by begin() until the commit; dropped on a rollback.
            $this->events->dispatchSaved(new TaskBoardChangedEvent($root, $actor, $sourceId, $target->getId(), $ids), TaskBoardEvents::TASK_BOARD_CHANGED);
        }

        return new TaskBoardMoveResult($target->getId(), $moved, $unchanged, $columns, $deps, $members, $parents);
    }

    /**
     * The column of a moved task: the map by its column's id, then by its name; else the target's column of the same
     * name; else the rule of a move ({@see TaskColumnRule}: a completed task to the last column, an open one to the first).
     *
     * @param array<string, BoardColumn> $map
     */
    private function targetColumn(Task $task, TaskBoard $target, array $map): ?BoardColumn
    {
        $source = $task->getColumn();
        if ($source instanceof BoardColumn) {
            $mapped = $map['id:' . $source->getId()] ?? $map['name:' . StatusColumns::normalize($source->getName())] ?? null;
            if ($mapped instanceof BoardColumn) {
                return $mapped;
            }
            foreach ($target->getColumns() as $column) {
                if (StatusColumns::normalize($column->getName()) === StatusColumns::normalize($source->getName())) {
                    return $column;
                }
            }
        }

        return TaskColumnRule::target($target, null, $task->isCompleted());
    }

    /**
     * The map's keys (a source column's id or name) to target columns, each named by id or by name.
     *
     * @param array<string, string> $columnMap
     *
     * @throws TaskBoardRestructureRefusedException `invalid` (`field` column_map) for a value naming no target column
     *
     * @return array<string, BoardColumn> keyed `id:<source column id>` and `name:<normalized source name>`
     */
    private function resolveMap(TaskBoard $target, array $columnMap): array
    {
        $resolved = [];
        foreach ($columnMap as $from => $to) {
            if (!is_string($to)) {
                throw new TaskBoardRestructureRefusedException(TaskBoardRestructureRefusedException::INVALID, ['field' => 'column_map']);
            }
            $column = null;
            foreach ($target->getColumns() as $candidate) {
                if ($candidate->getId() === $to) {
                    $column = $candidate;

                    break;
                }
            }
            if (!$column instanceof BoardColumn) {
                foreach ($target->getColumns() as $candidate) {
                    if (StatusColumns::normalize($candidate->getName()) === StatusColumns::normalize($to)) {
                        $column = $candidate;

                        break;
                    }
                }
            }
            if (!$column instanceof BoardColumn) {
                throw new TaskBoardRestructureRefusedException(TaskBoardRestructureRefusedException::INVALID, ['field' => 'column_map']);
            }
            $from                    = (string) $from;
            $resolved['id:' . $from] = $column;
            $resolved['name:' . StatusColumns::normalize($from)] ??= $column;
        }

        return $resolved;
    }

    /**
     * The tasks asked for, each once, without those under another task asked for (they move with it). Every task must be
     * of the target's tenant, byte for byte; another tenant's task is `not_found`, as an unknown id.
     *
     * @param list<string> $taskIds
     *
     * @return list<Task>
     */
    private function roots(TaskBoard $target, array $taskIds): array
    {
        $found = [];
        foreach ($taskIds as $taskId) {
            $task = is_string($taskId) && $taskId !== '' ? $this->tasks->findById($taskId) : null;
            if (!$task instanceof Task || $task->getId() !== $taskId || $task->getBoard()->getTenantKey() !== $target->getTenantKey()) {
                throw new TaskBoardRestructureRefusedException(TaskBoardRestructureRefusedException::NOT_FOUND);
            }
            $found[$task->getId()] = $task;
        }
        $roots = [];
        foreach ($found as $task) {
            $ancestor = $task->getParent();
            $nested   = false;
            while ($ancestor instanceof Task) {
                if (isset($found[$ancestor->getId()])) {
                    $nested = true;

                    break;
                }
                $ancestor = $ancestor->getParent();
            }
            if (!$nested) {
                $roots[] = $task;
            }
        }

        return $roots;
    }

    /**
     * `SELECT … FOR UPDATE` on every task of the moved trees, a level at a time (no-op on SQLite).
     *
     * @param list<Task> $roots
     */
    private function lock(array $roots): void
    {
        $level = array_map(static fn (Task $task): string => $task->getId(), $roots);
        $this->entityManager->createQueryBuilder()->select('t.id')->from(Task::class, 't')->where('t.id IN (:ids)')->setParameter('ids', $level)
            ->getQuery()->setLockMode(LockMode::PESSIMISTIC_WRITE)->getSingleColumnResult();
        while ($level !== []) {
            /** @var list<string> $level */
            $level = $this->entityManager->createQueryBuilder()->select('t.id')->from(Task::class, 't')->where('IDENTITY(t.parent) IN (:ids)')->setParameter('ids', $level)
                ->getQuery()->setLockMode(LockMode::PESSIMISTIC_WRITE)->getSingleColumnResult();
        }
    }

    /**
     * @return array<string, int>
     */
    private function nextPositions(TaskBoard $target): array
    {
        $positions = [];
        foreach ($this->tasks->findByBoard($target, true) as $existing) {
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

    /**
     * @throws TaskBoardRestructureRefusedException
     */
    private function requireFolder(string $folderId): TaskBoardFolder
    {
        return $this->folders->find($folderId) ?? throw new TaskBoardRestructureRefusedException(TaskBoardRestructureRefusedException::NOT_FOUND);
    }

    /**
     * @throws TaskBoardRestructureRefusedException
     */
    private function requireBoard(string $boardId): TaskBoard
    {
        $board = $boardId === '' ? null : $this->boards->findById($boardId);
        if (!$board instanceof TaskBoard || $board->getId() !== $boardId) {
            throw new TaskBoardRestructureRefusedException(TaskBoardRestructureRefusedException::NOT_FOUND);
        }

        return $board;
    }

    private function snapshot(TaskBoardFolder $folder): TaskBoardFolderSnapshot
    {
        return new TaskBoardFolderSnapshot(
            id: $folder->getId(),
            tenantKey: $folder->getTenantKey(),
            name: $folder->getName(),
            description: $folder->getDescription(),
            position: $folder->getPosition(),
            boardIds: $this->folders->boardIds($folder),
            createdAt: $folder->getCreatedAt(),
            updatedAt: $folder->getUpdatedAt(),
        );
    }
}
