<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Service;

use Closure;
use Doctrine\ORM\EntityManagerInterface;
use InvalidArgumentException;
use Nowo\TaskBoardBundle\Doctrine\TaskBoardEntityManager;
use Nowo\TaskBoardBundle\Dto\BoardColumnFormData;
use Nowo\TaskBoardBundle\Entity\BoardColumn;
use Nowo\TaskBoardBundle\Entity\Task;
use Nowo\TaskBoardBundle\Entity\TaskBoard;
use Nowo\TaskBoardBundle\Enum\ColumnType;
use Nowo\TaskBoardBundle\Event\AbstractColumnEvent;
use Nowo\TaskBoardBundle\Event\ColumnCreatedEvent;
use Nowo\TaskBoardBundle\Event\ColumnDeletedEvent;
use Nowo\TaskBoardBundle\Event\ColumnsReorderedEvent;
use Nowo\TaskBoardBundle\Event\ColumnUpdatedEvent;
use Nowo\TaskBoardBundle\Event\TaskBoardEvents;
use Nowo\TaskBoardBundle\Exception\ColumnTransitionRefusedException;
use Nowo\TaskBoardBundle\Exception\TaskReopenRefusedException;
use Nowo\TaskBoardBundle\Folder\FolderStatusSets;
use Nowo\TaskBoardBundle\Folder\TaskBoardRestructureRefusedException;
use Nowo\TaskBoardBundle\Port\Internal\SyncLimitCheckerInterface;
use Nowo\TaskBoardBundle\Port\Snapshots;
use Nowo\TaskBoardBundle\Repository\BoardColumnRepositoryInterface;
use Nowo\TaskBoardBundle\Support\ActorId;
use Nowo\TaskBoardBundle\Support\ColumnIcon;
use Nowo\TaskBoardBundle\Support\ColumnTasks;
use Nowo\TaskBoardBundle\Support\ColumnTypes;
use Nowo\TaskBoardBundle\Sync\Hook\LimitRefusal;
use Nowo\TaskBoardBundle\Transition\ColumnTransitionPolicyInterface;
use Nowo\TaskBoardBundle\Tree\TaskTreeRefusedException;
use Nowo\TaskBoardBundle\ValueObject\ColumnTransition;
use Nowo\TaskBoardBundle\ValueObject\ColumnTransitionVia;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

use function array_column;
use function array_filter;
use function array_keys;
use function array_map;
use function array_values;
use function count;
use function in_array;
use function is_string;
use function max;
use function sort;
use function trim;
use function usort;

use const ARRAY_FILTER_USE_BOTH;

/**
 * Adds, edits, reorders and deletes kanban columns on a board. Since 2.10.0 (spec 216) each saved change dispatches an
 * id-only event ({@see TaskBoardEvents::COLUMN_CREATED}, COLUMN_UPDATED, COLUMN_DELETED, COLUMNS_REORDERED) through
 * {@see TaskEventDeferral}, so a write that holds its events (a sync run, an import) dispatches them after its commit.
 * `$actor` is who made the change (null: none, e.g. a sync worker).
 */
final readonly class BoardColumnManager implements BoardColumnManagerInterface
{
    public function __construct(
        private BoardColumnRepositoryInterface $columnRepository,
        // Since 1.29.0 (audit HNd-L2): the host's limit, asked when a deletion would reopen completed tasks.
        private ?SyncLimitCheckerInterface $limits = null,
        // Since 2.10.0 (spec 216): the column events; none without a dispatcher.
        private ?EventDispatcherInterface $eventDispatcher = null,
        private ?TaskChangeContext $changeContext = null,
        // Since 2.19.0: reads a column's tasks when its type changes.
        private ?EntityManagerInterface $entityManager = null,
        // Since 2.16.0 (audit PC-M1): asked for every task a column deletion moves; none: every move is allowed.
        private ?ColumnTransitionPolicyInterface $transitions = null,
        // Since 2.19.0 (audit PW-H1): the rights a column edit on a board of a sharing folder needs; none: not asked.
        private ?FolderStatusSets $statusSets = null,
        // Since 2.19.0 (audit PW-L5): history rows and events for the tasks a retype completes or reopens.
        private ?TaskColumnFollow $follow = null,
        // Since 2.21.0 (Beacon spec 239, FR-013): the fixed lock order of the multi-row writes; none: written as before.
        private ?OrderedRowLockInterface $rowLock = null,
        // Since 3.0.0 (audit ZAS-1 / ZAS-2): TaskBoard rows the application's manager holds are read again by id at
        // each write entry (none: a service built by hand works on the rows it is given, as in 2.x).
        private ?TaskBoardEntityManager $rows = null,
    ) {
    }

    public function add(TaskBoard $board, BoardColumnFormData $data, ?object $actor = null): BoardColumn
    {
        $board = $this->own($board);
        // Audit PW-H1: on a board of a folder that shares its statuses the edit changes every board of the folder.
        if ($actor !== null) {
            $this->statusSets?->assertMayChangeColumns($actor, $board);
        }
        $position = -1;
        foreach ($board->getColumns() as $column) {
            $position = max($position, $column->getPosition());
        }

        $column = new BoardColumn(
            board: $board,
            name: trim($data->name),
            position: $position + 1,
            color: $this->normalize($data->color),
            // Since 2.9.0: one emoji or an icon id; anything else is not stored (the form and the API refuse it first).
            icon: ColumnIcon::normalize($data->icon),
        );
        $column->setHumansOnly($data->humansOnly);

        // Since 2.19.0 (FR-033): a typed column types the whole board first (its other columns keep their meaning).
        if ($data->type instanceof ColumnType) {
            $this->assertTypes($board, $column, $data->type);
            ColumnTypes::materialize($board);
            $column->setType($data->type);
        }
        // @igor-ignore - Request data: the row the caller passed, or TaskBoard's own copy of it (own(), audit ZAS-1); not service state.
        $board->addColumn($column);
        $this->columnRepository->save($column);
        $this->dispatch(new ColumnCreatedEvent($board->getId(), $board->getTenantKey(), $column->getId(), $column->getName(), $column->getPosition(), ActorId::of($actor), $this->cause(), $actor), TaskBoardEvents::COLUMN_CREATED);

        return $column;
    }

    /**
     * Non-string entries (e.g. nested arrays from columnOrder[0][]=…) are ignored.
     *
     * @param array<mixed> $orderedColumnIds
     */
    public function reorder(TaskBoard $board, array $orderedColumnIds, ?object $actor = null): void
    {
        $board = $this->own($board);
        // Audit PW-H1: on a board of a folder that shares its statuses the edit changes every board of the folder.
        if ($actor !== null) {
            $this->statusSets?->assertMayChangeColumns($actor, $board);
        }
        /** @var array<string, BoardColumn> $byId */
        $byId   = [];
        $before = [];
        foreach ($board->getColumns() as $column) {
            $byId[$column->getId()] = $column;
            $before[]               = [$column->getPosition(), $column->getId()];
        }
        sort($before);

        $updated = [];
        foreach ($orderedColumnIds as $columnId) {
            if (!is_string($columnId) || !isset($byId[$columnId])) {
                continue;
            }

            $updated[] = $byId[$columnId];
            unset($byId[$columnId]);
        }

        foreach ($byId as $column) {
            $updated[] = $column;
        }

        if ($updated !== []) {
            // Since 2.21.0 (Beacon spec 239, FR-013): the rows are locked by id first, never in identity-map order.
            // @igor-ignore - The closure runs once, now (inside transactional() or directly), and is never stored.
            $this->lockedWrite($this->ids($updated), function () use ($updated): void {
                foreach ($updated as $position => $column) {
                    $column->setPosition($position);
                }
                $this->columnRepository->saveAll($updated);
            });
            $order = array_map(static fn (BoardColumn $column): string => $column->getId(), $updated);
            if ($order !== array_column($before, 1)) {
                $this->dispatch(new ColumnsReorderedEvent($board->getId(), $board->getTenantKey(), $order, ActorId::of($actor), $this->cause(), $actor), TaskBoardEvents::COLUMNS_REORDERED);
            }
        }
    }

    public function update(BoardColumn $column, BoardColumnFormData $data, ?object $actor = null): void
    {
        $column = $this->own($column);
        // Audit PW-H1: on a board of a folder that shares its statuses the edit changes every board of the folder.
        if ($actor !== null) {
            $this->statusSets?->assertMayChangeColumns($actor, $column->getBoard());
        }
        $before  = [ColumnUpdatedEvent::FIELD_NAME => $column->getName(), ColumnUpdatedEvent::FIELD_COLOR => $column->getColor(), ColumnUpdatedEvent::FIELD_ICON => $column->getIcon(), ColumnUpdatedEvent::FIELD_TYPE => ColumnTypes::of($column)->value, ColumnUpdatedEvent::FIELD_HUMANS_ONLY => $column->isHumansOnly() ? '1' : '0'];
        $retyped = $data->type instanceof ColumnType && $data->type !== ColumnTypes::of($column) ? $data->type : null;
        if ($retyped instanceof ColumnType) {
            $this->assertTypes($column->getBoard(), $column, $retyped);
            $this->assertRetypeAllowed($column, $retyped);
        }
        // @igor-ignore - The closure runs once, now (inside the locked write or directly), and is never stored.
        $edit = function () use ($column, $data): void {
            // @igor-ignore - Request data: the row the caller passed, or TaskBoard's own copy of it (own(), audit ZAS-1); not service state.
            $column
                ->setName(trim($data->name))
                ->setColor($this->normalize($data->color))
                ->setIcon(ColumnIcon::normalize($data->icon))
                ->setHumansOnly($data->humansOnly);
        };
        if ($retyped instanceof ColumnType) {
            $board    = $column->getBoard();
            $columns  = array_values($board->getColumns()->toArray());
            $followed = [];
            // Since 2.21.0 (Beacon spec 239, FR-013): every column of the board, then the column's tasks as committed (each
            // row locked, by id), before the first write.
            // @igor-ignore - The closure runs once, now (inside transactional() or directly), and is never stored.
            $this->lockedWrite($this->ids($columns), function (?OrderedRowLockInterface $rowLock) use ($edit, $column, $board, $columns, $retyped, $actor, &$followed): void {
                $rowLock?->lockedColumnTasks($board->getId(), $column->getId(), [], true);
                $edit();
                // Since 2.19.0 (FR-033): the board's other columns keep their meaning; this column's tasks follow its type
                // (the flush of the columns saves them).
                ColumnTypes::materialize($board);
                // @igor-ignore - Request data: the row the caller passed, or TaskBoard's own copy of it (own(), audit ZAS-1); not service state.
                $column->setType($retyped);
                $snapshot = $this->follow?->snapshot($board) ?? [];
                $this->followType($column, $retyped);
                // Audit PW-L5: the tasks the retype completes or reopens get their history rows (this flush) and events.
                $followed = $this->follow?->record($board, $snapshot, $actor) ?? [];
                $this->columnRepository->saveAll($columns);
            });
            // Since 2.21.0: after the transaction, as before after the flush.
            foreach ($followed as [$event, $name]) {
                $this->eventDispatcher?->dispatch($event, $name);
            }
        } else {
            $edit();
            $this->columnRepository->save($column);
        }

        $after   = [ColumnUpdatedEvent::FIELD_NAME => $column->getName(), ColumnUpdatedEvent::FIELD_COLOR => $column->getColor(), ColumnUpdatedEvent::FIELD_ICON => $column->getIcon(), ColumnUpdatedEvent::FIELD_TYPE => ColumnTypes::of($column)->value, ColumnUpdatedEvent::FIELD_HUMANS_ONLY => $column->isHumansOnly() ? '1' : '0'];
        $changed = array_keys(array_filter($after, static fn (?string $value, string $field): bool => $value !== $before[$field], ARRAY_FILTER_USE_BOTH));
        if ($changed !== []) {
            $board = $column->getBoard();
            $this->dispatch(new ColumnUpdatedEvent($board->getId(), $board->getTenantKey(), $column->getId(), $column->getName(), $before[ColumnUpdatedEvent::FIELD_NAME], $changed, ActorId::of($actor), $this->cause(), $actor), TaskBoardEvents::COLUMN_UPDATED);
        }
    }

    /**
     * Since 2.16.0 (spec 234, Beacon block W): sets only the column's *humans only* flag, with COLUMN_UPDATED (field
     * `humansOnly`) when it changes. For a host that copies a board's configuration (no form, no API). False when it
     * already had that value.
     */
    public function setHumansOnly(BoardColumn $column, bool $humansOnly, ?object $actor = null): bool
    {
        $column = $this->own($column);
        if ($column->isHumansOnly() === $humansOnly) {
            return false;
        }
        if ($actor !== null) {
            $this->statusSets?->assertMayChangeColumns($actor, $column->getBoard());
        }
        // @igor-ignore - Request data: the row the caller passed, or TaskBoard's own copy of it (own(), audit ZAS-1); not service state.
        $column->setHumansOnly($humansOnly);
        $this->columnRepository->save($column);
        $board = $column->getBoard();
        $this->dispatch(new ColumnUpdatedEvent($board->getId(), $board->getTenantKey(), $column->getId(), $column->getName(), $column->getName(), [ColumnUpdatedEvent::FIELD_HUMANS_ONLY], ActorId::of($actor), $this->cause(), $actor), TaskBoardEvents::COLUMN_UPDATED);

        return true;
    }

    /**
     * Deletes a column (since 1.11): its tasks move to the end of the board's first remaining column (completed when
     * that column is the last one, reopened otherwise); the last column of a board is never deleted (false).
     *
     * Since 2.16.0 (audit PC-M1) the column transition policy is asked, before anything changes, for every task that moves
     * (operation `column_delete`, from the deleted column to the one its tasks go to), all or none: one refusal keeps the
     * column and every task. Without an actor (a worker) the policy is not asked, as for an import.
     *
     * @param ColumnTransitionVia|null $via since 2.16.0: how the tasks move; null: a person in the board UI (`web`)
     *
     * @throws TaskReopenRefusedException since 1.29.0, when the host's limit refuses the tasks it would reopen: nothing changes
     * @throws ColumnTransitionRefusedException since 2.16.0, when the column transition policy refuses a task: nothing changes
     * @throws TaskBoardRestructureRefusedException since 2.19.0 (audit PW-H1), `not_allowed` on a board whose folder shares
     *                                              its statuses, without the right on every board of the folder
     * @throws TaskTreeRefusedException since 2.21.0, `conflict` when a task of the column changed meanwhile and its copy
     *                                  holds unsaved changes: nothing changes
     */
    public function delete(BoardColumn $column, ?object $actor = null, ?ColumnTransitionVia $via = null): bool
    {
        $column = $this->own($column);
        // Audit PW-H1: on a board of a folder that shares its statuses the edit changes every board of the folder.
        if ($actor !== null) {
            $this->statusSets?->assertMayChangeColumns($actor, $column->getBoard());
        }
        $board     = $column->getBoard();
        $remaining = [];
        foreach ($board->getColumns() as $candidate) {
            if ($candidate !== $column) {
                $remaining[] = $candidate;
            }
        }

        if ($remaining === []) {
            return false;
        }

        usort($remaining, static fn (BoardColumn $a, BoardColumn $b): int => $a->getPosition() <=> $b->getPosition());
        // Since 2.19.0 (FR-033): the first open column takes them; it completes them when it is done (column type,
        // the last column on an untyped board).
        $target     = ColumnTypes::firstOpen($board, $remaining) ?? $remaining[0];
        $targetDone = ColumnTypes::isDone($target, $remaining);

        // Since 1.29.0 (audit HNd-L2): the completed tasks that move out of the last column are active again.
        if (!$targetDone) {
            $reopened = 0;
            foreach ($board->getTasks() as $task) {
                if ($task->getColumn() === $column && $task->isCompleted() && !$task->isArchived()) {
                    ++$reopened;
                }
            }
            $refusal = $reopened > 0 ? $this->limits?->check($board, $reopened, 0) : null;
            if ($refusal instanceof LimitRefusal) {
                throw new TaskReopenRefusedException($refusal);
            }
        }
        if ($actor !== null) {
            $lastOne = $remaining[count($remaining) - 1];
            $this->assertTasksMayMove($column, $column->getPosition() > $lastOne->getPosition(), $target, $target === $lastOne, $remaining, $actor, $via ?? ColumnTransitionVia::web(ColumnTransitionVia::OP_COLUMN_DELETE));
            // Audit PW-D1-L3: on a sharing folder the deletion moves the same column's tasks on the other boards too.
            $this->statusSets?->assertSiblingTransitions($actor, $board, [$column->getName() => $target->getName()], $via ?? ColumnTransitionVia::web(ColumnTransitionVia::OP_COLUMN_DELETE));
        }

        $position = 0;
        foreach ($board->getTasks() as $task) {
            if ($task->getColumn() === $target) {
                $position = max($position, $task->getPosition() + 1);
            }
        }

        $tasks = $this->tasksOf($column);
        $moved = [];
        // Since 2.21.0 (Beacon spec 239, FR-013): every column of the board (the deleted one included), then the tasks as
        // committed (audits SF-L3 / SF-L4): a task that entered the column while the deletion waited moves too, and the
        // target's next free position counts what entered it meanwhile.
        // @igor-ignore - The closure runs once, now (inside transactional() or directly), and is never stored.
        $this->lockedWrite([...$this->ids($remaining), $column->getId()], function (?OrderedRowLockInterface $rowLock) use ($board, $column, $remaining, $tasks, $target, $targetDone, $position, $actor, $via, &$moved): void {
            if ($rowLock instanceof OrderedRowLockInterface) {
                $read    = $this->ids($tasks);
                $tasks   = $this->byPosition($rowLock->lockedColumnTasks($board->getId(), $column->getId(), [], true));
                $entered = array_values(array_filter($tasks, static fn (Task $task): bool => !in_array($task->getId(), $read, true)));
                if ($actor !== null && $entered !== []) {
                    // The policy was asked for the tasks read before the lock; the ones that entered meanwhile are asked now.
                    $lastOne = $remaining[count($remaining) - 1];
                    $this->assertTasksMayMove($column, $column->getPosition() > $lastOne->getPosition(), $target, $target === $lastOne, $remaining, $actor, $via ?? ColumnTransitionVia::web(ColumnTransitionVia::OP_COLUMN_DELETE), $entered);
                }
                $position = 0;
                foreach ($rowLock->lockedColumnTasks($board->getId(), $target->getId(), [], true) as $task) {
                    $position = max($position, $task->getPosition() + 1);
                }
            }
            $moved = $this->ids($tasks);
            foreach ($tasks as $task) {
                $task->setColumn($target)->setPosition($position++);
                if ($targetDone && !$task->isCompleted()) {
                    $task->markCompleted();
                } elseif (!$targetDone && $task->isCompleted()) {
                    $task->markIncomplete();
                }
            }

            // @igor-ignore - Request data: the row the caller passed, or TaskBoard's own copy of it (own(), audit ZAS-1); not service state.
            $board->removeColumn($column);
            foreach ($remaining as $index => $candidate) {
                $candidate->setPosition($index);
            }

            // The flush also deletes the removed column (orphan removal) and saves the moved tasks.
            $this->columnRepository->saveAll($remaining);
        });
        $this->dispatch(new ColumnDeletedEvent($board->getId(), $board->getTenantKey(), $column->getId(), $column->getName(), $target->getId(), $moved, ActorId::of($actor), $this->cause(), $actor), TaskBoardEvents::COLUMN_DELETED);

        return true;
    }

    /**
     * Since 2.16.0 (audit PC-M1): every task of the deleted column, asked as it enters `$target` (the last column once the
     * deletion is done when `$targetIsLast`). The first refusal stops the deletion before anything changes.
     *
     * @param list<BoardColumn> $remaining the board's columns once the deletion is done, by position (since 2.19.0: the
     *                                     target's type is read among them)
     * @param list<Task>|null $tasks since 2.21.0: these tasks only (null: every task of the column)
     *
     * @throws ColumnTransitionRefusedException
     */
    private function assertTasksMayMove(BoardColumn $column, bool $columnIsLast, BoardColumn $target, bool $targetIsLast, array $remaining, object $actor, ColumnTransitionVia $via, ?array $tasks = null): void
    {
        if (!$this->transitions instanceof ColumnTransitionPolicyInterface) {
            return;
        }
        $board = $column->getBoard();
        $all   = ColumnTypes::ordered($board);
        foreach ($tasks ?? $this->tasksOf($column) as $task) {
            $transition = new ColumnTransition(
                Snapshots::board($board),
                Snapshots::task($task),
                Snapshots::column($column, $columnIsLast, $all),
                Snapshots::column($target, $targetIsLast, $remaining),
                $actor,
                ActorId::of($actor),
                $via,
                array_values($task->getTags()),
            );
            $decision = $this->transitions->decide($transition);
            if (!$decision->allowed) {
                throw new ColumnTransitionRefusedException($transition, $decision);
            }
        }
    }

    /**
     * Since 2.21.0 (Beacon spec 239, FR-013): runs `$write` in one transaction once the columns are locked (by id), the
     * first rows every write that changes them or their tasks takes; `$write` gets the lock service to read and lock the
     * tasks as committed (null without one). The caller dispatches the events after it. Without a lock service (a
     * hand-built manager) `$write` runs as before.
     *
     * @param list<string> $columnIds
     * @param Closure(?OrderedRowLockInterface): void $write
     */
    private function lockedWrite(array $columnIds, Closure $write): void
    {
        if (!$this->rowLock instanceof OrderedRowLockInterface) {
            $write(null);

            return;
        }
        $rowLock = $this->rowLock;
        // @igor-ignore - The closure runs once, now, inside transactional(); the stateless lock keeps no reference to it.
        $rowLock->transactional(static function () use ($rowLock, $columnIds, $write): void {
            $rowLock->lockColumns($columnIds);
            $write($rowLock);
        });
    }

    /**
     * @param list<Task> $tasks
     *
     * @return list<Task> by position, then id
     */
    private function byPosition(array $tasks): array
    {
        usort($tasks, static fn (Task $a, Task $b): int => [$a->getPosition(), $a->getId()] <=> [$b->getPosition(), $b->getId()]);

        return $tasks;
    }

    /**
     * @param list<BoardColumn|Task> $entities
     *
     * @return list<string>
     */
    private function ids(array $entities): array
    {
        return array_map(static fn (BoardColumn|Task $entity): string => $entity->getId(), $entities);
    }

    /**
     * Since 2.17.0 (audit PG-L2): the tasks of a column in its order (position, then id). The board's collection has no
     * defined order (MySQL loads it in the primary key's), so the policy and the moves follow this one.
     *
     * @return list<Task>
     */
    private function tasksOf(BoardColumn $column): array
    {
        $tasks = [];
        foreach ($column->getBoard()->getTasks() as $task) {
            if ($task->getColumn() === $column) {
                $tasks[] = $task;
            }
        }

        return $this->byPosition($tasks);
    }

    private function cause(): ?string
    {
        return $this->changeContext?->current();
    }

    private function dispatch(AbstractColumnEvent $event, string $name): void
    {
        $this->eventDispatcher?->dispatch($event, $name);
    }

    private function normalize(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = trim($value);

        return $value === '' ? null : $value;
    }

    /**
     * Since 2.19.0 (FR-033): at most two closed columns on a board.
     *
     * @throws InvalidArgumentException
     */
    private function assertTypes(TaskBoard $board, BoardColumn $column, ColumnType $type): void
    {
        $columns = ColumnTypes::ordered($board);
        $types   = [];
        foreach ($columns as $candidate) {
            if ($candidate !== $column) {
                $types[] = ColumnTypes::of($candidate, $columns);
            }
        }
        $types[] = $type;
        if (!ColumnTypes::allowed($types)) {
            throw new InvalidArgumentException('A board has at most ' . ColumnType::MAX_CLOSED . ' closed columns.');
        }
    }

    /**
     * Since 2.19.0: a column that stops being done reopens its completed tasks, so the host's limit is asked first.
     *
     * @throws TaskReopenRefusedException
     */
    private function assertRetypeAllowed(BoardColumn $column, ColumnType $type): void
    {
        if ($type->isDone() || !ColumnTypes::isDone($column)) {
            return;
        }
        $reopened = 0;
        foreach (ColumnTasks::of($this->entityManager, $column) as $task) {
            $reopened += (int) ($task->isCompleted() && !$task->isArchived());
        }
        $refusal = $reopened > 0 ? $this->limits?->check($column->getBoard(), $reopened, 0) : null;
        if ($refusal instanceof LimitRefusal) {
            throw new TaskReopenRefusedException($refusal);
        }
    }

    /** Since 2.19.0: the tasks of the column are completed when it is done, reopened otherwise. */
    private function followType(BoardColumn $column, ColumnType $type): void
    {
        foreach (ColumnTasks::of($this->entityManager, $column) as $task) {
            if ($type->isDone() && !$task->isCompleted()) {
                $task->markCompleted();
            } elseif (!$type->isDone() && $task->isCompleted()) {
                $task->markIncomplete();
            }
        }
    }

    /**
     * Since 3.0.0 (audit ZAS-1 / ZAS-2): TaskBoard's own copy of a row the application's manager holds (read again by id),
     * else the row itself. The row is request data handed in by the caller, never service state.
     *
     * @template T of object
     *
     * @param T $row
     *
     * @return T
     */
    private function own(object $row): object
    {
        return $this->rows instanceof TaskBoardEntityManager ? $this->rows->attach($row) : $row;
    }
}
