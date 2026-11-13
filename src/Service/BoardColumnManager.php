<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Service;

use Nowo\TaskBoardBundle\Dto\BoardColumnFormData;
use Nowo\TaskBoardBundle\Entity\BoardColumn;
use Nowo\TaskBoardBundle\Entity\TaskBoard;
use Nowo\TaskBoardBundle\Event\AbstractColumnEvent;
use Nowo\TaskBoardBundle\Event\ColumnCreatedEvent;
use Nowo\TaskBoardBundle\Event\ColumnDeletedEvent;
use Nowo\TaskBoardBundle\Event\ColumnsReorderedEvent;
use Nowo\TaskBoardBundle\Event\ColumnUpdatedEvent;
use Nowo\TaskBoardBundle\Event\TaskBoardEvents;
use Nowo\TaskBoardBundle\Exception\TaskReopenRefusedException;
use Nowo\TaskBoardBundle\Repository\BoardColumnRepositoryInterface;
use Nowo\TaskBoardBundle\Support\ActorId;
use Nowo\TaskBoardBundle\Support\ColumnIcon;
use Nowo\TaskBoardBundle\Sync\Hook\LimitRefusal;
use Nowo\TaskBoardBundle\Sync\Hook\SyncLimitCheckerInterface;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

use function array_column;
use function array_filter;
use function array_keys;
use function array_map;
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
        $before = [ColumnUpdatedEvent::FIELD_NAME => $column->getName(), ColumnUpdatedEvent::FIELD_COLOR => $column->getColor(), ColumnUpdatedEvent::FIELD_ICON => $column->getIcon(), ColumnUpdatedEvent::FIELD_HUMANS_ONLY => $column->isHumansOnly() ? '1' : '0'];
        $column
            ->setName(trim($data->name))
            ->setColor($this->normalize($data->color))
            ->setIcon(ColumnIcon::normalize($data->icon))
            ->setHumansOnly($data->humansOnly);

        $this->columnRepository->save($column);

        $after   = [ColumnUpdatedEvent::FIELD_NAME => $column->getName(), ColumnUpdatedEvent::FIELD_COLOR => $column->getColor(), ColumnUpdatedEvent::FIELD_ICON => $column->getIcon(), ColumnUpdatedEvent::FIELD_HUMANS_ONLY => $column->isHumansOnly() ? '1' : '0'];
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
     * @throws TaskReopenRefusedException since 1.29.0, when the host's limit refuses the tasks it would reopen: nothing changes
     */
    public function delete(BoardColumn $column, ?object $actor = null): bool
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
        $target  = $remaining[0];
        $lastOne = $remaining[count($remaining) - 1];

        // Since 1.29.0 (audit HNd-L2): the completed tasks that move out of the last column are active again.
        if ($target !== $lastOne) {
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

        $position = 0;
        foreach ($board->getTasks() as $task) {
            if ($task->getColumn() === $target) {
                $position = max($position, $task->getPosition() + 1);
            }
        }

        $moved = [];
        foreach ($board->getTasks() as $task) {
            if ($task->getColumn() !== $column) {
                continue;
            }

            $moved[] = $task->getId();
            $task->setColumn($target)->setPosition($position++);
            if ($target === $lastOne && !$task->isCompleted()) {
                $task->markCompleted();
            } elseif ($target !== $lastOne && $task->isCompleted()) {
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
}
