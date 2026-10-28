<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Tests\Unit\Service;

use Nowo\TaskBoardBundle\Entity\Task;
use Nowo\TaskBoardBundle\Entity\TaskBoard;
use Nowo\TaskBoardBundle\Service\TaskGanttBuilder;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;
use stdClass;

final class TaskGanttBuilderTreeTest extends TestCase
{
    public function testSubtaskWhoseParentIsNotInTheListIsDrawnAtRootLevel(): void
    {
        $board  = new TaskBoard('Demo', 'demo', new stdClass());
        $parent = new Task($board, 'Hidden parent', new stdClass());
        $child  = new Task($board, 'Orphan child', new stdClass(), parent: $parent);

        $timeline = (new TaskGanttBuilder())->build([$child]);

        self::assertCount(1, $timeline->items);
        self::assertSame('Orphan child', $timeline->items[0]->title);
        self::assertSame(0, $timeline->items[0]->depth);
    }

    public function testRangeIsPaddedByTwoDaysOnEachSide(): void
    {
        $task = new Task(new TaskBoard('Demo', 'demo', new stdClass()), 'Today', new stdClass());

        $timeline = (new TaskGanttBuilder())->build([$task]);

        self::assertCount(TaskGanttBuilder::MIN_RANGE_DAYS, $timeline->dayKeys, 'short plans are drawn over at least three weeks');
        self::assertSame(2, $timeline->items[0]->startIndex);
    }

    public function testCyclicParentDataYieldsAnEmptyTimeline(): void
    {
        $board = new TaskBoard('Demo', 'demo', new stdClass());
        $a     = new Task($board, 'A', new stdClass());
        $b     = new Task($board, 'B', new stdClass(), parent: $a);
        (new ReflectionProperty(Task::class, 'parent'))->setValue($a, $b);

        $timeline = (new TaskGanttBuilder())->build([$a, $b]);

        self::assertSame([], $timeline->items);
        self::assertNotSame([], $timeline->dayKeys);
    }
}
