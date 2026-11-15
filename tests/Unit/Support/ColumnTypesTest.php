<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Tests\Unit\Support;

use Nowo\TaskBoardBundle\Entity\BoardColumn;
use Nowo\TaskBoardBundle\Entity\Task;
use Nowo\TaskBoardBundle\Entity\TaskBoard;
use Nowo\TaskBoardBundle\Enum\ColumnType;
use Nowo\TaskBoardBundle\Support\ColumnTasks;
use Nowo\TaskBoardBundle\Support\ColumnTypes;
use PHPUnit\Framework\TestCase;
use stdClass;

use function array_map;

final class ColumnTypesTest extends TestCase
{
    public function testUntypedBoardsFollowPosition(): void
    {
        $board = $this->board(['A', 'B', 'C']);
        self::assertSame(['open', 'custom', 'closed'], $this->types($board));
        self::assertSame('C', ColumnTypes::doneColumn($board)?->getName());
        self::assertSame(['A', 'B'], $this->names(ColumnTypes::openColumns($board)));
        self::assertSame('A', ColumnTypes::firstOpen($board)?->getName());
        self::assertSame(['closed'], $this->types($this->board(['Only'])));
        self::assertNull(ColumnTypes::doneColumn($this->board([])));
    }

    public function testTypedBoards(): void
    {
        $board = $this->board(['A', 'B', 'C']);
        ColumnTypes::materialize($board);
        ColumnTypes::materialize($board);
        self::assertSame(ColumnType::Open, ColumnTypes::ordered($board)[0]->getStoredType());

        $custom = $this->board(['X', 'Y']);
        ColumnTypes::ordered($custom)[1]->setType(ColumnType::Custom);
        self::assertSame(['custom', 'custom'], $this->types($custom), 'an untyped column of a typed board is custom');
        self::assertSame('Y', ColumnTypes::doneColumn($custom)?->getName(), 'no done column: the last one');
        self::assertSame('X', ColumnTypes::firstOpen($custom)?->getName(), 'no open column: the first not done');

        $done = $this->board(['P', 'Q']);
        foreach (ColumnTypes::ordered($done) as $column) {
            $column->setType(ColumnType::Done);
        }
        self::assertSame(['P', 'Q'], $this->names(ColumnTypes::openColumns($done)), 'all done: every column');

        self::assertTrue(ColumnTypes::allowed([ColumnType::Closed, ColumnType::Closed, ColumnType::Open]));
        self::assertFalse(ColumnTypes::allowed([ColumnType::Closed, ColumnType::Closed, ColumnType::Closed]));
        self::assertTrue(ColumnType::Done->isDone());
        self::assertFalse(ColumnType::Custom->isDone());
    }

    public function testColumnTasksWithoutAManagerReadTheCollection(): void
    {
        $board  = $this->board(['A']);
        $column = ColumnTypes::ordered($board)[0];
        $task   = new Task(board: $board, title: 'T', creator: new stdClass(), column: $column);
        $column->getTasks()->add($task);
        self::assertSame([$task], ColumnTasks::of(null, $column));
    }

    /**
     * @param list<string> $names
     */
    private function board(array $names): TaskBoard
    {
        $board = new TaskBoard('Board', 'board', new stdClass());
        foreach ($names as $position => $name) {
            $board->addColumn(new BoardColumn($board, $name, $position));
        }

        return $board;
    }

    /** @return list<string> */
    private function types(TaskBoard $board): array
    {
        return array_map(static fn (BoardColumn $column): string => ColumnTypes::of($column)->value, ColumnTypes::ordered($board));
    }

    /**
     * @param list<BoardColumn> $columns
     *
     * @return list<string>
     */
    private function names(array $columns): array
    {
        return array_map(static fn (BoardColumn $column): string => $column->getName(), $columns);
    }
}
