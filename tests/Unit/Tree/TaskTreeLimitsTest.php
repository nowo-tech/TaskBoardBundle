<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Tests\Unit\Tree;

use InvalidArgumentException;
use Nowo\TaskBoardBundle\Entity\Task;
use Nowo\TaskBoardBundle\Entity\TaskBoard;
use Nowo\TaskBoardBundle\Tree\TaskTreeLimits;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;
use stdClass;

/**
 * Beacon spec 234, T004: `max_subtask_depth` and the bounded, iterative walks every tree write shares.
 */
final class TaskTreeLimitsTest extends TestCase
{
    private TaskBoard $board;

    protected function setUp(): void
    {
        $this->board = new TaskBoard('Roadmap', 'roadmap', new stdClass());
    }

    public function testDepthAndHeight(): void
    {
        $chain = $this->chain(4); // depths 0..3
        self::assertSame([0, 1, 2, 3], array_map(TaskTreeLimits::depthOf(...), $chain));
        self::assertSame(3, TaskTreeLimits::heightOf($chain[0]));
        self::assertSame(0, TaskTreeLimits::heightOf($chain[3]));
        self::assertSame(2, TaskTreeLimits::heightOf($chain[0], 1), 'the walk stops one level past the bound');
    }

    public function testTheLimitRefusesOnlyWhatGoesDeeperThanItAndThanBefore(): void
    {
        $limits = new TaskTreeLimits(3);
        self::assertSame(3, $limits->maxDepth());
        self::assertTrue($limits->allows(3));
        self::assertFalse($limits->allows(4));
        self::assertTrue($limits->allows(5, 6), 'no deeper than before: allowed');
        self::assertFalse($limits->allows(6, 5));
        self::assertSame(TaskTreeLimits::SAFETY_CAP, (new TaskTreeLimits())->maxDepth());
        self::assertTrue($limits->allowsTree(1, 2));
        self::assertFalse($limits->allowsTree(2, 2));
        self::assertFalse($limits->allowsTree(TaskTreeLimits::UNBOUNDED, 0));
        self::assertFalse($limits->allowsTree(0, TaskTreeLimits::UNBOUNDED));
    }

    public function testChildMoveAndAllowedParent(): void
    {
        $limits = new TaskTreeLimits(2);
        $chain  = $this->chain(4); // depths 0..3, the last ones past the limit (saved before it)
        self::assertTrue($limits->allowsChildOf($chain[1]));
        self::assertFalse($limits->allowsChildOf($chain[2]));
        self::assertSame($chain[1], $limits->allowedParent($chain[3]), 'the deepest ancestor a child may have');
        self::assertSame($chain[1], $limits->allowedParent($chain[1]));

        $subtree = $this->chain(2);
        $other   = $this->task('Other');
        self::assertTrue($limits->allowsMove($subtree[0], $other), 'depth 1 to 2');
        self::assertFalse($limits->allowsMove($subtree[0], $chain[1]), 'its child would reach depth 3');
        self::assertTrue($limits->allowsMove($chain[2], $chain[0]), 'a tree saved deeper may move up');
        self::assertTrue($limits->allowsMove($chain[3], $chain[1]), 'no deeper than it is');
        self::assertFalse($limits->allowsMove($other, $chain[2]));
        self::assertTrue((new TaskTreeLimits())->allowsMove($subtree[0], $chain[3]), 'no limit');
        self::assertSame($chain[0], (new TaskTreeLimits(1))->allowedParent($chain[1]));
        self::assertNull((new TaskTreeLimits(1))->allowedParent($this->forceLoop()), 'a loop: the top level');
        self::assertSame($chain[0], (new TaskTreeLimits(1))->allowedParent($chain[3]));
    }

    public function testALoopInTheDataIsInfinitelyDeepAndEndsEveryWalk(): void
    {
        $a = $this->task('A');
        $b = $this->task('B', $a);
        $this->forceParent($a, $b); // A ↔ B, never written by the bundle

        self::assertSame(TaskTreeLimits::UNBOUNDED, TaskTreeLimits::depthOf($a));
        self::assertSame(TaskTreeLimits::UNBOUNDED, TaskTreeLimits::heightOf($a));
        $limits = new TaskTreeLimits(5);
        self::assertFalse($limits->allowsChildOf($b));
        self::assertNull($limits->allowedParent($b), 'placed at the top level');
        self::assertFalse($limits->allowsMove($this->task('C'), $a));
    }

    public function testAChainPastTheSafetyCapFailsClosed(): void
    {
        $deepest = null;
        for ($i = 0; $i <= TaskTreeLimits::SAFETY_CAP + 1; ++$i) {
            $deepest = new Task($this->board, 'L' . $i, new stdClass(), parent: $deepest);
        }
        self::assertInstanceOf(Task::class, $deepest);
        self::assertSame(TaskTreeLimits::UNBOUNDED, TaskTreeLimits::depthOf($deepest));
        self::assertFalse((new TaskTreeLimits(3))->allowsChildOf($deepest));
    }

    public function testTheLimitIsAtLeastOne(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new TaskTreeLimits(0);
    }

    /**
     * @return list<Task> a chain, top first
     */
    private function chain(int $length): array
    {
        $chain  = [];
        $parent = null;
        for ($i = 0; $i < $length; ++$i) {
            $parent  = $this->task('Level ' . $i, $parent);
            $chain[] = $parent;
        }

        return $chain;
    }

    private function task(string $title, ?Task $parent = null): Task
    {
        $task = new Task($this->board, $title, new stdClass(), parent: $parent);
        $parent?->getSubtasks()->add($task); // as Doctrine loads it

        return $task;
    }

    private function forceLoop(): Task
    {
        $a = $this->task('Loop A');
        $b = $this->task('Loop B', $a);
        $this->forceParent($a, $b);

        return $b;
    }

    private function forceParent(Task $task, Task $parent): void
    {
        (new ReflectionProperty(Task::class, 'parent'))->setValue($task, $parent);
        $parent->getSubtasks()->add($task);
    }
}
