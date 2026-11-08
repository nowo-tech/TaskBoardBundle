<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Move;

use Doctrine\ORM\EntityManagerInterface;
use Nowo\TaskBoardBundle\Copy\TaskColumnRule;
use Nowo\TaskBoardBundle\Entity\BoardColumn;
use Nowo\TaskBoardBundle\Entity\Task;
use Nowo\TaskBoardBundle\Entity\TaskBoard;
use Nowo\TaskBoardBundle\Entity\TaskComment;
use Nowo\TaskBoardBundle\Entity\TaskDependency;
use Nowo\TaskBoardBundle\Entity\TaskMember;
use Nowo\TaskBoardBundle\Exception\TaskCopyRefusedException;
use Nowo\TaskBoardBundle\Number\TaskNumberScope;
use Nowo\TaskBoardBundle\Security\TaskBoardResourceAccessChecker;
use Nowo\TaskBoardBundle\Service\TaskMemberAssigner;
use Nowo\TaskBoardBundle\Support\UserLabel;
use Nowo\TaskBoardBundle\Sync\Entity\TaskSyncFieldValue;
use Nowo\TaskBoardBundle\Sync\Entity\TaskSyncItem;
use Nowo\TaskBoardBundle\Sync\People\SyncUser;

use function array_keys;
use function array_map;
use function array_pop;
use function array_unique;
use function array_values;
use function count;
use function hash;
use function in_array;
use function json_encode;
use function sort;

use const JSON_THROW_ON_ERROR;

/**
 * Since 2.3.0 (spec 217, FR-001): plans a move of a task and its whole subtree to another board. Read-only: it writes
 * nothing, and {@see TaskMover} applies exactly what it returns.
 *
 * Column: {@see TaskColumnRule} (a completed task goes to the last column and stays completed; `$columnMap` maps a source
 * column id to a target column). Members not assignable on the target are removed, and so is every dependency between a
 * moved task and one that stays (dependencies are same-board only). A synced task is detached from its provider for good.
 */
final readonly class TaskMovePlanner
{
    /** `move.max_subtasks`. */
    public const DEFAULT_MAX_SUBTASKS = 200;

    public function __construct(
        private EntityManagerInterface $entityManager,
        private TaskMemberAssigner $members,
        private ?TaskNumberScope $numberScope = null,
        private int $maxSubtasks = self::DEFAULT_MAX_SUBTASKS,
        private bool $syncEnabled = false,
        // Since audit IC-L1: with an actor, every task of the subtree must be one they may edit.
        private ?TaskBoardResourceAccessChecker $access = null,
    ) {
    }

    /**
     * @param array<string, string> $columnMap source column id => target column id (an open column of the target)
     * @param object|null $actor since audit IC-L1: who moves; every task of the subtree must pass `canEditTask()` for them
     *
     * @throws TaskCopyRefusedException `same_board`, `board_archived`, `subtree_too_large`, `subtask_not_editable` (with
     *                                  `%task%`, the task's title), `subtask_hidden` (a task they cannot view, unnamed)
     */
    public function plan(Task $task, TaskBoard $target, array $columnMap = [], ?object $actor = null): TaskMovePlan
    {
        $source = $task->getBoard();
        if ($target->getId() === $source->getId()) {
            throw new TaskCopyRefusedException('same_board');
        }
        if ($target->isArchived()) {
            throw new TaskCopyRefusedException('board_archived');
        }
        $tree = self::subtree($task);
        if (count($tree) - 1 > $this->maxSubtasks) {
            throw new TaskCopyRefusedException('subtree_too_large', ['%count%' => count($tree) - 1, '%max%' => $this->maxSubtasks]);
        }
        if ($actor !== null && $this->access instanceof TaskBoardResourceAccessChecker) {
            $this->refuseLocked($this->access, $actor, $tree);
        }

        $ids     = array_map(static fn (Task $node): string => $node->getId(), $tree);
        $columns = [];
        $applied = [];
        $members = [];
        $labels  = [];
        $deps    = [];
        $others  = [];
        $people  = [];
        $open    = 0;
        $counts  = ['documents' => 0, 'links' => 0, 'reimport' => 0, 'entries' => 0, 'seconds' => 0];
        foreach ($tree as $node) {
            $from = $node->getColumn();
            $to   = self::targetColumn($node, $target, $columnMap);
            $key  = ($from?->getId() ?? '') . '>' . ($to?->getId() ?? '');
            $columns[$key] ??= ['source' => $from?->getName() ?? '', 'sourceId' => $from?->getId(), 'target' => $to?->getName(), 'targetId' => $to?->getId(), 'tasks' => 0];
            ++$columns[$key]['tasks'];
            if ($from instanceof BoardColumn && $to instanceof BoardColumn && !$node->isCompleted()) {
                $applied[$from->getId()] = $to->getId();
            }
            $open += (int) (!$node->isCompleted() && !$node->isArchived());

            foreach ($node->getMembers() as $member) {
                if (!$this->members->canBeAssigned($target, $member->getUser())) {
                    $members[] = $member->getId();
                    $labels[]  = UserLabel::of($member->getUser());
                }
            }
            foreach ([...$node->getOutgoingDependencies(), ...$node->getIncomingDependencies()] as $dependency) {
                $other = $dependency->getSourceTask() === $node ? $dependency->getTargetTask() : $dependency->getSourceTask();
                if (!in_array($other->getId(), $ids, true) && !in_array($dependency->getId(), $deps, true)) {
                    $deps[]   = $dependency->getId();
                    $others[] = $other->getTitle();
                }
            }
            $counts['documents'] += $node->getDocuments()->count();
            foreach ($node->getLinks() as $link) {
                ++$counts['links'];
                $counts['reimport'] += (int) ($link->getImportSource() !== null && $link->getExternalId() !== null);
            }
            $counts['seconds'] += $node->getTotalTimeSeconds();
            foreach ($node->getTimeEntries() as $entry) {
                ++$counts['entries'];
                $counts['seconds'] += 60 * $entry->getMinutes();
                $people[SyncUser::id($entry->getUser()) ?? 'name:' . $entry->getUserName()] = true;
            }
        }
        $labels = array_values(array_unique($labels));
        sort($labels);
        $comments = $this->entityManager->getRepository(TaskComment::class)->count(['task' => $ids]);
        $synced   = $this->syncMapped() ? $this->entityManager->getRepository(TaskSyncItem::class)->count(['task' => $ids]) : 0;
        $fields   = $this->syncMapped() ? $this->importedFields($ids) : [];
        $number   = $this->numberScope instanceof TaskNumberScope && $this->numberScope->keyOf($source) !== $this->numberScope->keyOf($target);
        $columns  = array_values($columns);

        $snapshot = $this->snapshot($task);
        $facts    = [$ids, $target->getId(), $task->getParent()?->getId(), $columns, $members, $deps, $open, $comments, $counts, count($people), $synced, $number, $fields, $snapshot];

        return new TaskMovePlan(
            task: $task,
            sourceBoard: $source,
            targetBoard: $target,
            taskIds: $ids,
            becomesTopLevel: $task->getParent() instanceof Task,
            columns: $columns,
            columnMap: $applied,
            membersRemoved: $labels,
            memberIds: $members,
            dependenciesRemoved: $others,
            dependencyIds: $deps,
            openTasks: $open,
            comments: $comments,
            documents: $counts['documents'],
            links: $counts['links'],
            timeEntries: $counts['entries'],
            timePeople: count(array_keys($people)),
            trackedSeconds: $counts['seconds'],
            syncDetached: $synced,
            reimportKeys: $counts['reimport'],
            numberChanges: $number,
            importedFields: $fields,
            snapshot: $snapshot,
            fingerprint: hash('sha256', json_encode($facts, JSON_THROW_ON_ERROR)),
        );
    }

    /**
     * Audit IC-L1: every task that would move must be one `$actor` may edit. A task they can see is named; one they cannot
     * see is not.
     *
     * @param list<Task> $tree
     *
     * @throws TaskCopyRefusedException
     */
    private function refuseLocked(TaskBoardResourceAccessChecker $access, object $actor, array $tree): void
    {
        foreach ($tree as $node) {
            if (!$access->canEditTask($actor, $node)) {
                throw $access->canViewTask($actor, $node) ? new TaskCopyRefusedException('subtask_not_editable', ['%task%' => $node->getTitle()]) : new TaskCopyRefusedException('subtask_hidden');
            }
        }
    }

    /**
     * Audit IC-L2: the facts of the tree as the database holds them now — its tasks with their completion and archive state,
     * every dependency touching them and every member — read with queries, not from loaded collections. The mover compares
     * it again inside its transaction, so a write made since the plan refuses the move instead of slipping through it.
     */
    public function snapshot(Task $task): string
    {
        $rows  = [];
        $ids   = [$task->getId()];
        $level = $ids;
        while ($level !== []) {
            /** @var list<array{id: string, c: mixed, a: mixed}> $children */
            $children = $this->entityManager->createQueryBuilder()
                ->select('t.id AS id', 't.completedAt AS c', 't.archivedAt AS a')->from(Task::class, 't')
                ->where('IDENTITY(t.parent) IN (:ids)')->setParameter('ids', $level)
                ->getQuery()->getArrayResult();
            $level = [];
            foreach ($children as $child) {
                $level[] = (string) $child['id'];
                $rows[]  = [(string) $child['id'], $child['c'] !== null, $child['a'] !== null];
            }
            $ids = [...$ids, ...$level];
        }
        $self = $this->entityManager->createQueryBuilder()
            ->select('t.completedAt AS c', 't.archivedAt AS a', 'IDENTITY(t.parent) AS p')->from(Task::class, 't')
            ->where('t.id = :id')->setParameter('id', $task->getId())->getQuery()->getArrayResult()[0] ?? [];
        $deps = $this->entityManager->createQueryBuilder()
            ->select('d.id AS id')->from(TaskDependency::class, 'd')
            ->where('IDENTITY(d.sourceTask) IN (:ids) OR IDENTITY(d.targetTask) IN (:ids)')->setParameter('ids', $ids)
            ->getQuery()->getSingleColumnResult();
        $members = $this->entityManager->createQueryBuilder()
            ->select('m.id AS id')->from(TaskMember::class, 'm')
            ->where('IDENTITY(m.task) IN (:ids)')->setParameter('ids', $ids)
            ->getQuery()->getSingleColumnResult();
        sort($rows);
        sort($deps);
        sort($members);

        return hash('sha256', json_encode([$rows, ($self['c'] ?? null) !== null, ($self['a'] ?? null) !== null, $self['p'] ?? null, $deps, $members], JSON_THROW_ON_ERROR));
    }

    /**
     * Audit IC-I5: the sync tables exist (mapped), whatever `sync.enabled` says now: items a move must tombstone may be there.
     */
    public function syncMapped(): bool
    {
        return $this->syncEnabled || !$this->entityManager->getMetadataFactory()->isTransient(TaskSyncItem::class);
    }

    /**
     * Audit IC-I4: the names of the imported fields with a value on these tasks. Values point at the task, so they stay on
     * the moved task as read-only data.
     *
     * @param list<string> $ids
     *
     * @return list<string>
     */
    private function importedFields(array $ids): array
    {
        /** @var list<string> $names */
        $names = $this->entityManager->createQueryBuilder()
            ->select('DISTINCT f.name')->from(TaskSyncFieldValue::class, 'v')->join('v.field', 'f')
            ->where('IDENTITY(v.task) IN (:ids)')->setParameter('ids', $ids)
            ->orderBy('f.name')->getQuery()->getSingleColumnResult();

        return array_values(array_map('strval', $names));
    }

    /**
     * The task and every descendant (archived ones included: the whole tree moves), the task first.
     *
     * @return list<Task>
     */
    public static function subtree(Task $task): array
    {
        $tree  = [];
        $stack = [$task];
        while ($stack !== []) {
            $node   = array_pop($stack);
            $tree[] = $node;
            foreach ($node->getSubtasks() as $child) {
                $stack[] = $child;
            }
        }

        return $tree;
    }

    /**
     * @param array<string, string> $columnMap
     */
    public static function targetColumn(Task $task, TaskBoard $target, array $columnMap): ?BoardColumn
    {
        $chosen = $task->getColumn() instanceof BoardColumn ? ($columnMap[$task->getColumn()->getId()] ?? null) : null;

        return TaskColumnRule::target($target, $task->getColumn(), $task->isCompleted(), $chosen);
    }
}
