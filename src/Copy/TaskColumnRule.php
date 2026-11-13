<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Copy;

use Nowo\TaskBoardBundle\Entity\BoardColumn;
use Nowo\TaskBoardBundle\Entity\TaskBoard;
use Nowo\TaskBoardBundle\Support\ColumnTypes;
use Nowo\TaskBoardBundle\Sync\Status\StatusColumns;

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
        $columns = ColumnTypes::ordered($target);
        if ($columns === []) {
            return null;
        }
        if ($completed) {
            // Since 2.17.0 (FR-033): the first done or closed column (the last one on an untyped board).
            return ColumnTypes::doneColumn($target, $columns);
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

        // Since 2.17.0 (FR-033): the first open column.
        return ColumnTypes::firstOpen($target, $columns) ?? $columns[0];
    }

    /**
     * The columns an open task may be put in on `$target`: since 2.17.0 every column that is not done (column type);
     * on an untyped board every column but the last, or the only one, as before.
     *
     * @return list<BoardColumn>
     */
    public static function openColumns(TaskBoard $target): array
    {
        return ColumnTypes::openColumns($target);
    }
}
