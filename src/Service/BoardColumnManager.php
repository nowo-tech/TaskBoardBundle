<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Service;

use Nowo\TaskBoardBundle\Dto\BoardColumnFormData;
use Nowo\TaskBoardBundle\Entity\BoardColumn;
use Nowo\TaskBoardBundle\Entity\TaskBoard;
use Nowo\TaskBoardBundle\Repository\BoardColumnRepositoryInterface;

use function count;
use function is_string;
use function max;
use function trim;
use function usort;

/**
 * Adds, edits, reorders and deletes kanban columns on a board.
 */
final readonly class BoardColumnManager
{
    public function __construct(
        private BoardColumnRepositoryInterface $columnRepository,
    ) {
    }

    public function add(TaskBoard $board, BoardColumnFormData $data): BoardColumn
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
            icon: $this->normalize($data->icon),
        );

        $board->addColumn($column);
        $this->columnRepository->save($column);

        return $column;
    }

    /**
     * Non-string entries (e.g. nested arrays from columnOrder[0][]=…) are ignored.
     *
     * @param array<mixed> $orderedColumnIds
     */
    public function reorder(TaskBoard $board, array $orderedColumnIds): void
    {
        /** @var array<string, BoardColumn> $byId */
        $byId = [];
        foreach ($board->getColumns() as $column) {
            $byId[$column->getId()] = $column;
        }

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
        }
    }

    public function update(BoardColumn $column, BoardColumnFormData $data): void
    {
        $column
            ->setName(trim($data->name))
            ->setColor($this->normalize($data->color))
            ->setIcon($this->normalize($data->icon));

        $this->columnRepository->save($column);
    }

    /**
     * Deletes a column (since 1.11): its tasks move to the end of the board's first remaining column (completed when
     * that column is the last one, reopened otherwise); the last column of a board is never deleted (false).
     */
    public function delete(BoardColumn $column): bool
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
        $target   = $remaining[0];
        $lastOne  = $remaining[count($remaining) - 1];
        $position = 0;
        foreach ($board->getTasks() as $task) {
            if ($task->getColumn() === $target) {
                $position = max($position, $task->getPosition() + 1);
            }
        }

        foreach ($board->getTasks() as $task) {
            if ($task->getColumn() !== $column) {
                continue;
            }

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

        return true;
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
