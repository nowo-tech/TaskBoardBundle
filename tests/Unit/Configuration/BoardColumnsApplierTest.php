<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Tests\Unit\Configuration;

use InvalidArgumentException;
use Nowo\TaskBoardBundle\Configuration\BoardColumnSpec;
use Nowo\TaskBoardBundle\Configuration\BoardColumnsApplier;
use Nowo\TaskBoardBundle\Configuration\BoardColumnsRefusedException;
use Nowo\TaskBoardBundle\Entity\BoardColumn;
use Nowo\TaskBoardBundle\Entity\Task;
use Nowo\TaskBoardBundle\Entity\TaskBoard;
use Nowo\TaskBoardBundle\Enum\ColumnType;
use Nowo\TaskBoardBundle\Exception\TaskReopenRefusedException;
use Nowo\TaskBoardBundle\Support\ColumnTypes;
use Nowo\TaskBoardBundle\Sync\Hook\LimitRefusal;
use Nowo\TaskBoardBundle\Sync\Hook\SyncLimitCheckerInterface;
use PHPUnit\Framework\TestCase;
use stdClass;

use function array_map;

final class BoardColumnsApplierTest extends TestCase
{
    public function testMergesRenamesCreatesAndRemoves(): void
    {
        $board = $this->board(['Todo', 'Doing', 'Review', 'Empty']);
        [$todo, $doing, $review] = ColumnTypes::ordered($board);
        $a = $this->task($board, $todo, 'A');
        $b = $this->task($board, $review, 'B');
        $b->markCompleted();
        $archived = $this->task($board, ColumnTypes::ordered($board)[3], 'Old');
        $archived->archive();

        $removed = (new BoardColumnsApplier())->apply($board, [
            new BoardColumnSpec('Todo', ColumnType::Open),
            new BoardColumnSpec('In progress', ColumnType::Custom, '#123456', 'tabler:bug'),
            new BoardColumnSpec('Done', ColumnType::Closed),
        ], [$doing->getId() => 'In progress', 'Review' => 'In progress']);

        self::assertSame(['Todo', 'In progress', 'Done'], array_map(static fn (BoardColumn $c): string => $c->getName(), ColumnTypes::ordered($board)));
        self::assertSame($doing, ColumnTypes::ordered($board)[1], 'the first matched column is kept');
        self::assertSame(['Review', 'Empty'], array_map(static fn (BoardColumn $c): string => $c->getName(), $removed));
        self::assertSame($doing, $b->getColumn(), 'merged into the kept column');
        self::assertFalse($b->isCompleted(), 'a custom column reopens it');
        self::assertSame($todo, $a->getColumn());
        self::assertSame($todo, $archived->getColumn(), 'an archived task of a removed column goes to the first column');
        self::assertSame(['Todo:open', 'In progress:custom', 'Done:closed'], array_map(static fn (BoardColumnSpec $s): string => $s->name . ':' . $s->type->value, BoardColumnsApplier::specsOf($board)));
    }

    public function testRefusals(): void
    {
        $board = $this->board(['Todo', 'Busy']);
        $this->task($board, ColumnTypes::ordered($board)[1], 'Busy task');
        $applier = new BoardColumnsApplier();
        try {
            $applier->apply($board, [new BoardColumnSpec('Todo')]);
            self::fail('orphan');
        } catch (BoardColumnsRefusedException $refused) {
            self::assertSame(['Busy'], $refused->columns);
            self::assertSame($board->getId(), $refused->boardId);
        }
        foreach ([[], [new BoardColumnSpec('A'), new BoardColumnSpec('a')], [new BoardColumnSpec('A', ColumnType::Closed), new BoardColumnSpec('B', ColumnType::Closed), new BoardColumnSpec('C', ColumnType::Closed)]] as $specs) {
            try {
                $applier->apply($board, $specs);
                self::fail('invalid specs');
            } catch (InvalidArgumentException) {
            }
        }
        $this->expectException(InvalidArgumentException::class);
        $applier->apply($board, [new BoardColumnSpec('Todo')], ['Busy' => 'Nowhere']);
    }

    public function testReopeningAsksTheLimit(): void
    {
        $board = $this->board(['Todo', 'Done']);
        $task  = $this->task($board, ColumnTypes::ordered($board)[1], 'Done task');
        $task->markCompleted();
        $limits = new class implements SyncLimitCheckerInterface {
            public function check(TaskBoard $board, int $newTasks, int $newColumns): ?LimitRefusal
            {
                return new LimitRefusal('Plan full');
            }
        };
        $this->expectException(TaskReopenRefusedException::class);
        (new BoardColumnsApplier($limits))->apply($board, [new BoardColumnSpec('Todo', ColumnType::Open), new BoardColumnSpec('Done', ColumnType::Custom)]);
    }

    public function testSpecs(): void
    {
        $spec = BoardColumnSpec::fromArray(['name' => 'Hecho', 'type' => 'closed', 'color' => '#fff', 'icon' => null]);
        self::assertSame(['name' => 'Hecho', 'type' => 'closed', 'color' => '#fff', 'icon' => null], $spec->toArray());
        self::assertSame(ColumnType::Custom, BoardColumnSpec::fromArray(['name' => 'X'])->type);
        foreach ([null, ['type' => 'open'], ['name' => 'X', 'type' => 'nope'], ['name' => 'X', 'color' => 3], ['name' => 'X', 'humansOnly' => 'yes']] as $bad) {
            try {
                BoardColumnSpec::fromArray($bad);
                self::fail('invalid');
            } catch (InvalidArgumentException) {
            }
        }
        $this->expectException(InvalidArgumentException::class);
        new BoardColumnSpec(' padded ');
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

    private function task(TaskBoard $board, BoardColumn $column, string $title): Task
    {
        $task = new Task(board: $board, title: $title, creator: new stdClass(), column: $column);
        $column->getTasks()->add($task);

        return $task;
    }
}
