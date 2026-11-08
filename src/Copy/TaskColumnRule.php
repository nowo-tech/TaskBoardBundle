<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Copy;

use Nowo\TaskBoardBundle\Entity\BoardColumn;
use Nowo\TaskBoardBundle\Entity\TaskBoard;
use Nowo\TaskBoardBundle\Sync\Status\StatusColumns;

use function array_slice;
use function array_values;
use function count;

/**
 * Since 2.3.0 (specs 215, 217): the column a copied or moved task lands in. A completed task goes to the last (done)
 * column. An open one goes to the column chosen, else its own column on the same board, else the target's column with the
 * same name (the sync's one rule, {@see StatusColumns::normalize()}), else the first; never to the last column of a board
 * that has more than one.
 */
final class TaskColumnRule
{
    public static function target(TaskBoard $target, ?BoardColumn $source, bool $completed, ?string $chosenId = null): ?BoardColumn
    {
        $columns = array_values($target->getColumns()->toArray());
        if ($columns === []) {
            return null;
        }
        $last = $columns[count($columns) - 1];
        if ($completed) {
            return $last;
        }
        $open = self::openColumns($target);

        foreach ($open as $column) {
            if ($chosenId !== null && $column->getId() === $chosenId) {
                return $column;
            }
        }
        if ($source instanceof BoardColumn) {
            foreach ($open as $column) {
                if ($column->getId() === $source->getId()) {
                    return $column;
                }
            }
            $name = StatusColumns::normalize($source->getName());
            foreach ($open as $column) {
                if (StatusColumns::normalize($column->getName()) === $name) {
                    return $column;
                }
            }
        }

        return $columns[0];
    }

    /**
     * The columns an open task may be put in on `$target` (every column but the last, or the only one).
     *
     * @return list<BoardColumn>
     */
    public static function openColumns(TaskBoard $target): array
    {
        $columns = array_values($target->getColumns()->toArray());

        return count($columns) <= 1 ? $columns : array_slice($columns, 0, -1);
    }
}
