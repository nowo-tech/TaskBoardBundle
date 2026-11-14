<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Support;

use Nowo\TaskBoardBundle\Entity\BoardColumn;
use Nowo\TaskBoardBundle\Entity\TaskBoard;
use Nowo\TaskBoardBundle\Enum\ColumnType;
use Nowo\TaskBoardBundle\Service\BoardColumnManager;

use function array_filter;
use function array_key_last;
use function array_values;
use function count;
use function usort;

/**
 * Since 2.19.0 (Beacon spec 234, FR-033): the type of a board's columns, and the columns that make a task done.
 *
 * - A board none of whose columns carries a type (every board before 2.19.0) keeps its meaning by position: the first
 *   column is `open`, the last `closed` (its tasks are done; a single column is `closed`), the rest `custom` — what the
 *   2.19.0 migration writes.
 * - Once one column carries a type, every column without one is `custom`. Setting a type through
 *   {@see BoardColumnManager} writes the position types of the others first
 *   ({@see self::materialize()}), so a board is never half typed by accident.
 *
 * Stateless: static helpers only.
 */
final class ColumnTypes
{
    /**
     * The columns of the board by position.
     *
     * @return list<BoardColumn>
     */
    public static function ordered(TaskBoard $board): array
    {
        $columns = array_values($board->getColumns()->toArray());
        usort($columns, static fn (BoardColumn $a, BoardColumn $b): int => $a->getPosition() <=> $b->getPosition());

        return $columns;
    }

    /**
     * @param list<BoardColumn>|null $columns the board's columns by position (default: the column's board's)
     */
    public static function of(BoardColumn $column, ?array $columns = null): ColumnType
    {
        $columns ??= self::ordered($column->getBoard());
        $stored = $column->getStoredType();
        if ($stored instanceof ColumnType) {
            return $stored;
        }
        if (self::typed($columns)) {
            return ColumnType::Custom;
        }
        $index = 0;
        foreach ($columns as $position => $candidate) {
            if ($candidate === $column || $candidate->getId() === $column->getId()) {
                $index = $position;
            }
        }

        return match (true) {
            $columns !== [] && $index === array_key_last($columns) => ColumnType::Closed,
            $index === 0                                           => ColumnType::Open,
            default                                                => ColumnType::Custom,
        };
    }

    /**
     * Whether a task in this column is done. A board of one untyped column: its only column (the last), as before 2.19.0.
     *
     * @param list<BoardColumn>|null $columns
     */
    public static function isDone(BoardColumn $column, ?array $columns = null): bool
    {
        return self::of($column, $columns ?? self::ordered($column->getBoard()))->isDone();
    }

    /**
     * The column a task goes to when it is marked done: the first done or closed column, else the last column.
     *
     * @param list<BoardColumn>|null $columns
     */
    public static function doneColumn(TaskBoard $board, ?array $columns = null): ?BoardColumn
    {
        $columns ??= self::ordered($board);
        foreach ($columns as $column) {
            if (self::isDone($column, $columns)) {
                return $column;
            }
        }

        return $columns === [] ? null : $columns[array_key_last($columns)];
    }

    /**
     * The columns an open task may be in: every column that is not done (all of them on a board of one column).
     *
     * @param list<BoardColumn>|null $columns
     *
     * @return list<BoardColumn>
     */
    public static function openColumns(TaskBoard $board, ?array $columns = null): array
    {
        $columns ??= self::ordered($board);
        $open = array_values(array_filter($columns, static fn (BoardColumn $column): bool => !self::isDone($column, $columns)));

        return $open === [] ? $columns : $open;
    }

    /**
     * Where a new or reopened task goes: the first `open` column, else the first column that is not done.
     *
     * @param list<BoardColumn>|null $columns
     */
    public static function firstOpen(TaskBoard $board, ?array $columns = null): ?BoardColumn
    {
        $columns ??= self::ordered($board);
        foreach ($columns as $column) {
            if (self::of($column, $columns) === ColumnType::Open) {
                return $column;
            }
        }

        return self::openColumns($board, $columns)[0] ?? null;
    }

    /**
     * Writes the position types on every column of an untyped board, so setting one column's type changes nothing else.
     */
    public static function materialize(TaskBoard $board): void
    {
        $columns = self::ordered($board);
        if (self::typed($columns)) {
            return;
        }
        $types = [];
        foreach ($columns as $column) {
            $types[] = self::of($column, $columns);
        }
        foreach ($columns as $index => $column) {
            $column->setType($types[$index]);
        }
    }

    /**
     * Whether these types are allowed together: at most {@see ColumnType::MAX_CLOSED} closed columns.
     *
     * @param list<ColumnType> $types
     */
    public static function allowed(array $types): bool
    {
        return count(array_filter($types, static fn (ColumnType $type): bool => $type === ColumnType::Closed)) <= ColumnType::MAX_CLOSED;
    }

    /**
     * @param list<BoardColumn> $columns
     */
    public static function typed(array $columns): bool
    {
        foreach ($columns as $column) {
            if ($column->getStoredType() instanceof ColumnType) {
                return true;
            }
        }

        return false;
    }
}
