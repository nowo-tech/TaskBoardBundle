<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Service;

use Doctrine\ORM\EntityManagerInterface;
use InvalidArgumentException;
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
use Nowo\TaskBoardBundle\Port\Snapshots;
use Nowo\TaskBoardBundle\Repository\BoardColumnRepositoryInterface;
use Nowo\TaskBoardBundle\Support\ActorId;
use Nowo\TaskBoardBundle\Support\ColumnIcon;
use Nowo\TaskBoardBundle\Support\ColumnTasks;
use Nowo\TaskBoardBundle\Support\ColumnTypes;
use Nowo\TaskBoardBundle\Sync\Hook\LimitRefusal;
use Nowo\TaskBoardBundle\Sync\Hook\SyncLimitCheckerInterface;
use Nowo\TaskBoardBundle\Transition\ColumnTransitionPolicyInterface;
use Nowo\TaskBoardBundle\ValueObject\ColumnTransition;
use Nowo\TaskBoardBundle\ValueObject\ColumnTransitionVia;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

use function array_column;
use function array_filter;
use function array_keys;
use function array_map;
use function array_values;
use function count;
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
    ) {
    }

    public function add(TaskBoard $board, BoardColumnFormData $data, ?object $actor = null): BoardColumn
    {
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
        $board->addColumn($column);
        $this->columnRepository->save($column);
        $this->dispatch(new ColumnCreatedEvent($board->getId(), $board->getTenantKey(), $column->getId(), $column->getName(), $column->getPosition(), ActorId::of($actor), $this->cause()), TaskBoardEvents::COLUMN_CREATED);

        return $column;
    }

    /**
     * Non-string entries (e.g. nested arrays from columnOrder[0][]=…) are ignored.
     *
     * @param array<mixed> $orderedColumnIds
     */
    public function reorder(TaskBoard $board, array $orderedColumnIds, ?object $actor = null): void
    {
        /** @var array<string, BoardColumn> $byId */
        $byId   = [];
        $before = [];
        foreach ($board->getColumns() as $column) {
            $byId[$column->getId()] = $column;
            $before[]               = [$column->getPosition(), $column->getId()];
        }
        sort($before);

        $updated  = [];
        $position = 0;

        foreach ($orderedColumnIds as $columnId) {
            if (!is_string($columnId) || !isset($byId[$columnId])) {
                continue;
            }

            $byId[$columnId]->setPosition($position);
            $updated[] = $byId[$columnId];
            unset($byId[$columnId]);
            ++$position;
        }

        foreach ($byId as $column) {
            $column->setPosition($position);
            $updated[] = $column;
            ++$position;
        }

        if ($updated !== []) {
            $this->columnRepository->saveAll($updated);
            $order = array_map(static fn (BoardColumn $column): string => $column->getId(), $updated);
            if ($order !== array_column($before, 1)) {
                $this->dispatch(new ColumnsReorderedEvent($board->getId(), $board->getTenantKey(), $order, ActorId::of($actor), $this->cause()), TaskBoardEvents::COLUMNS_REORDERED);
            }
        }
    }

    public function update(BoardColumn $column, BoardColumnFormData $data, ?object $actor = null): void
    {
        $before  = [ColumnUpdatedEvent::FIELD_NAME => $column->getName(), ColumnUpdatedEvent::FIELD_COLOR => $column->getColor(), ColumnUpdatedEvent::FIELD_ICON => $column->getIcon(), ColumnUpdatedEvent::FIELD_TYPE => ColumnTypes::of($column)->value, ColumnUpdatedEvent::FIELD_HUMANS_ONLY => $column->isHumansOnly() ? '1' : '0'];
        $retyped = $data->type instanceof ColumnType && $data->type !== ColumnTypes::of($column) ? $data->type : null;
        if ($retyped instanceof ColumnType) {
            $this->assertTypes($column->getBoard(), $column, $retyped);
            $this->assertRetypeAllowed($column, $retyped);
        }
        $column
            ->setName(trim($data->name))
            ->setColor($this->normalize($data->color))
            ->setIcon(ColumnIcon::normalize($data->icon))
            ->setHumansOnly($data->humansOnly);
        if ($retyped instanceof ColumnType) {
            // Since 2.19.0 (FR-033): the board's other columns keep their meaning; this column's tasks follow its type
            // (the flush of the columns saves them).
            ColumnTypes::materialize($column->getBoard());
            $column->setType($retyped);
            $this->followType($column, $retyped);
            $this->columnRepository->saveAll(array_values($column->getBoard()->getColumns()->toArray()));
        } else {
            $this->columnRepository->save($column);
        }

        $after   = [ColumnUpdatedEvent::FIELD_NAME => $column->getName(), ColumnUpdatedEvent::FIELD_COLOR => $column->getColor(), ColumnUpdatedEvent::FIELD_ICON => $column->getIcon(), ColumnUpdatedEvent::FIELD_TYPE => ColumnTypes::of($column)->value, ColumnUpdatedEvent::FIELD_HUMANS_ONLY => $column->isHumansOnly() ? '1' : '0'];
        $changed = array_keys(array_filter($after, static fn (?string $value, string $field): bool => $value !== $before[$field], ARRAY_FILTER_USE_BOTH));
        if ($changed !== []) {
            $board = $column->getBoard();
            $this->dispatch(new ColumnUpdatedEvent($board->getId(), $board->getTenantKey(), $column->getId(), $column->getName(), $before[ColumnUpdatedEvent::FIELD_NAME], $changed, ActorId::of($actor), $this->cause()), TaskBoardEvents::COLUMN_UPDATED);
        }
    }

    /**
     * Since 2.16.0 (spec 234, Beacon block W): sets only the column's *humans only* flag, with COLUMN_UPDATED (field
     * `humansOnly`) when it changes. For a host that copies a board's configuration (no form, no API). False when it
     * already had that value.
     */
    public function setHumansOnly(BoardColumn $column, bool $humansOnly, ?object $actor = null): bool
    {
        if ($column->isHumansOnly() === $humansOnly) {
            return false;
        }
        $column->setHumansOnly($humansOnly);
        $this->columnRepository->save($column);
        $board = $column->getBoard();
        $this->dispatch(new ColumnUpdatedEvent($board->getId(), $board->getTenantKey(), $column->getId(), $column->getName(), $column->getName(), [ColumnUpdatedEvent::FIELD_HUMANS_ONLY], ActorId::of($actor), $this->cause()), TaskBoardEvents::COLUMN_UPDATED);

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
     */
    public function delete(BoardColumn $column, ?object $actor = null, ?ColumnTransitionVia $via = null): bool
    {
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
        }

        $position = 0;
        foreach ($board->getTasks() as $task) {
            if ($task->getColumn() === $target) {
                $position = max($position, $task->getPosition() + 1);
            }
        }

        $moved = [];
        foreach (self::tasksOf($column) as $task) {
            $moved[] = $task->getId();
            $task->setColumn($target)->setPosition($position++);
            if ($targetDone && !$task->isCompleted()) {
                $task->markCompleted();
            } elseif (!$targetDone && $task->isCompleted()) {
                $task->markIncomplete();
            }
        }

        $board->removeColumn($column);
        foreach ($remaining as $index => $candidate) {
            $candidate->setPosition($index);
        }

        // The flush also deletes the removed column (orphan removal) and saves the moved tasks.
        $this->columnRepository->saveAll($remaining);
        $this->dispatch(new ColumnDeletedEvent($board->getId(), $board->getTenantKey(), $column->getId(), $column->getName(), $target->getId(), $moved, ActorId::of($actor), $this->cause()), TaskBoardEvents::COLUMN_DELETED);

        return true;
    }

    /**
     * Since 2.16.0 (audit PC-M1): every task of the deleted column, asked as it enters `$target` (the last column once the
     * deletion is done when `$targetIsLast`). The first refusal stops the deletion before anything changes.
     *
     * @param list<BoardColumn> $remaining the board's columns once the deletion is done, by position (since 2.19.0: the
     *                                     target's type is read among them)
     *
     * @throws ColumnTransitionRefusedException
     */
    private function assertTasksMayMove(BoardColumn $column, bool $columnIsLast, BoardColumn $target, bool $targetIsLast, array $remaining, object $actor, ColumnTransitionVia $via): void
    {
        if (!$this->transitions instanceof ColumnTransitionPolicyInterface) {
            return;
        }
        $board = $column->getBoard();
        $all   = ColumnTypes::ordered($board);
        foreach (self::tasksOf($column) as $task) {
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
     * Since 2.17.0 (audit PG-L2): the tasks of a column in its order (position, then id). The board's collection has no
     * defined order (MySQL loads it in the primary key's), so the policy and the moves follow this one.
     *
     * @return list<Task>
     */
    private static function tasksOf(BoardColumn $column): array
    {
        $tasks = [];
        foreach ($column->getBoard()->getTasks() as $task) {
            if ($task->getColumn() === $column) {
                $tasks[] = $task;
            }
        }
        usort($tasks, static fn (Task $a, Task $b): int => [$a->getPosition(), $a->getId()] <=> [$b->getPosition(), $b->getId()]);

        return $tasks;
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
}
