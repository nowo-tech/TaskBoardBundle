<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Tests\Unit;

use DateTimeImmutable;
use Nowo\TaskBoardBundle\Entity\BoardColumn;
use Nowo\TaskBoardBundle\Entity\Task;
use Nowo\TaskBoardBundle\Entity\TaskBoard;
use Nowo\TaskBoardBundle\Entity\TaskDependency;
use Nowo\TaskBoardBundle\Enum\TaskDependencyType;
use Nowo\TaskBoardBundle\Enum\TaskPriority;
use Nowo\TaskBoardBundle\Service\TaskGanttBuilder;
use PHPUnit\Framework\TestCase;
use stdClass;

final class TaskGanttBuilderTest extends TestCase
{
    public function testBuildsTimelineForPastIncompleteTask(): void
    {
        $board = new TaskBoard('Demo', 'demo', new stdClass());
        $board->addColumn(new BoardColumn($board, 'Todo', 0));

        $task = new Task(
            board: $board,
            title: 'Stale',
            creator: new stdClass(),
            dueAt: new DateTimeImmutable('-5 days'),
        );

        $timeline = (new TaskGanttBuilder())->build([$task]);

        self::assertCount(1, $timeline->items);
        self::assertGreaterThanOrEqual(0, $timeline->items[0]->endIndex);
    }

    public function testBuildsItemsWithDueDateAndSubtaskDepth(): void
    {
        $board  = new TaskBoard('Demo', 'demo', new stdClass());
        $column = new BoardColumn($board, 'To do', 0);
        $board->addColumn($column);

        $parent = new Task(
            board: $board,
            title: 'Parent',
            creator: new stdClass(),
            column: $column,
            priority: TaskPriority::High,
            dueAt: new DateTimeImmutable('+3 days'),
        );
        $child = new Task(
            board: $board,
            title: 'Child',
            creator: new stdClass(),
            column: $column,
            parent: $parent,
            estimatedMinutes: 480,
        );

        $builder  = new TaskGanttBuilder();
        $timeline = $builder->build([$parent, $child]);

        self::assertCount(2, $timeline->items);
        self::assertSame(0, $timeline->items[0]->depth);
        self::assertSame(1, $timeline->items[1]->depth);
        self::assertSame('Parent', $timeline->items[0]->title);
        self::assertNotEmpty($timeline->dayKeys);
        self::assertGreaterThanOrEqual($timeline->items[0]->startIndex, $timeline->items[0]->endIndex);
    }

    public function testCollectsBlockingDependenciesAsLinks(): void
    {
        $board = new TaskBoard('Demo', 'demo', new stdClass());
        $board->addColumn(new BoardColumn($board, 'To do', 0));

        $first  = new Task($board, 'First', new stdClass());
        $second = new Task($board, 'Second', new stdClass());
        $first->getOutgoingDependencies()->add(new TaskDependency($first, $second, TaskDependencyType::Blocks));

        $timeline = (new TaskGanttBuilder())->build([$first, $second]);

        self::assertCount(1, $timeline->links);
        self::assertSame($first->getId(), $timeline->links[0]->fromId);
        self::assertSame($second->getId(), $timeline->links[0]->toId);
    }

    public function testEmptyBoardReturnsDefaultRange(): void
    {
        $timeline = (new TaskGanttBuilder())->build([]);

        self::assertSame([], $timeline->items);
        self::assertNotEmpty($timeline->dayKeys);
    }
}
