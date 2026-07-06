<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Tests\Unit;

use Nowo\TaskBoardBundle\Dto\BoardColumnFormData;
use Nowo\TaskBoardBundle\Entity\BoardColumn;
use Nowo\TaskBoardBundle\Entity\TaskBoard;
use Nowo\TaskBoardBundle\Repository\BoardColumnRepositoryInterface;
use Nowo\TaskBoardBundle\Service\BoardColumnManager;
use PHPUnit\Framework\TestCase;
use stdClass;

use function count;

final class BoardColumnManagerTest extends TestCase
{
    public function testAddColumnAppendsAtEnd(): void
    {
        $board = new TaskBoard('Demo', 'demo', new stdClass());
        $board->addColumn(new BoardColumn($board, 'To do', 0, '#111111'));
        $board->addColumn(new BoardColumn($board, 'Done', 1, '#222222'));

        $repository = $this->createMock(BoardColumnRepositoryInterface::class);
        $repository->expects(self::once())
            ->method('save')
            ->with(self::callback(static fn (BoardColumn $column): bool => $column->getName() === 'Review'
                && $column->getPosition() === 2
                && $column->getColor() === '#333333'));

        $manager = new BoardColumnManager($repository);
        $column  = $manager->add($board, new BoardColumnFormData(name: 'Review', color: '#333333'));

        self::assertSame('Review', $column->getName());
        self::assertSame(2, $column->getPosition());
    }

    public function testReorderColumnsUpdatesPositions(): void
    {
        $board  = new TaskBoard('Demo', 'demo', new stdClass());
        $first  = new BoardColumn($board, 'A', 0);
        $second = new BoardColumn($board, 'B', 1);
        $third  = new BoardColumn($board, 'C', 2);
        $board->addColumn($first)->addColumn($second)->addColumn($third);

        $repository = $this->createMock(BoardColumnRepositoryInterface::class);
        $repository->expects(self::once())
            ->method('saveAll')
            ->with(self::callback(static fn (array $columns): bool => count($columns) === 3
                && $columns[0]->getName() === 'C'
                && $columns[1]->getName() === 'A'
                && $columns[2]->getName() === 'B'));

        $manager = new BoardColumnManager($repository);
        $manager->reorder($board, [$third->getId(), $first->getId(), $second->getId()]);

        self::assertSame(0, $third->getPosition());
        self::assertSame(1, $first->getPosition());
        self::assertSame(2, $second->getPosition());
    }

    public function testUpdateColumnChangesNameAndColor(): void
    {
        $board  = new TaskBoard('Demo', 'demo', new stdClass());
        $column = new BoardColumn($board, 'To do', 0, '#111111');
        $board->addColumn($column);

        $repository = $this->createMock(BoardColumnRepositoryInterface::class);
        $repository->expects(self::once())
            ->method('save')
            ->with(self::callback(static fn (BoardColumn $saved): bool => $saved->getName() === 'Backlog'
                && $saved->getColor() === '#abcdef'));

        $manager = new BoardColumnManager($repository);
        $manager->update($column, new BoardColumnFormData(name: 'Backlog', color: '#abcdef'));

        self::assertSame('Backlog', $column->getName());
        self::assertSame('#abcdef', $column->getColor());
    }

    public function testAddColumnNormalizesEmptyColor(): void
    {
        $board      = new TaskBoard('Demo', 'demo', new stdClass());
        $repository = $this->createMock(BoardColumnRepositoryInterface::class);
        $repository->expects(self::once())->method('save');

        $column = (new BoardColumnManager($repository))->add($board, new BoardColumnFormData(name: 'Backlog', color: '  '));

        self::assertNull($column->getColor());
    }

    public function testReorderIgnoresUnknownIdsAndAppendsRemainingColumns(): void
    {
        $board  = new TaskBoard('Demo', 'demo', new stdClass());
        $first  = new BoardColumn($board, 'A', 0);
        $second = new BoardColumn($board, 'B', 1);
        $board->addColumn($first)->addColumn($second);

        $repository = $this->createMock(BoardColumnRepositoryInterface::class);
        $repository->expects(self::once())->method('saveAll');

        (new BoardColumnManager($repository))->reorder($board, ['missing', $first->getId()]);

        self::assertSame(0, $first->getPosition());
        self::assertSame(1, $second->getPosition());
    }
}
