<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Tests\Unit\Support;

use Nowo\TaskBoardBundle\Copy\TaskCopier;
use Nowo\TaskBoardBundle\Copy\TaskCopyOptions;
use Nowo\TaskBoardBundle\Dto\TaskFormData;
use Nowo\TaskBoardBundle\Entity\Task;
use Nowo\TaskBoardBundle\Entity\TaskBoard;
use Nowo\TaskBoardBundle\Exception\TaskCopyRefusedException;
use Nowo\TaskBoardBundle\Move\TaskMovePlanner;
use Nowo\TaskBoardBundle\Repository\TaskRepositoryInterface;
use Nowo\TaskBoardBundle\Service\TaskChangeRecorder;
use Nowo\TaskBoardBundle\Service\TaskGanttBuilder;
use Nowo\TaskBoardBundle\Service\TaskManager;
use Nowo\TaskBoardBundle\Support\SubtaskProgress;
use Nowo\TaskBoardBundle\Support\TaskTree;
use Nowo\TaskBoardBundle\Tree\TaskTreeLimits;
use Nowo\TaskBoardBundle\Tree\TaskTreeRefusedException;
use Nowo\TaskBoardBundle\Twig\TaskBoardTwigExtension;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;
use ReflectionProperty;
use stdClass;

/**
 * Audit PX-M2 / PX-L4 (TaskBoard 2.20.0): every tree walk is iterative, remembers what it saw and is bounded, with or
 * without `max_subtask_depth`: a chain 50,000 levels deep (the recursive walks segfaulted at 40,000) and a loop in the data
 * end; the write walks refuse a loop (`tree_loop`) or a tree past the safety cap.
 */
final class BoundedTreeWalksTest extends TestCase
{
    private const DEEP = 50_000;

    private TaskBoard $board;

    protected function setUp(): void
    {
        $this->board = new TaskBoard('Roadmap', 'roadmap', new stdClass());
    }

    public function testADeepChainIsWalkedWithoutRecursion(): void
    {
        [$root, $chain] = $this->chain(self::DEEP);
        $done           = $chain[self::DEEP - 1];
        $done->markCompleted();

        self::assertSame([1, self::DEEP - 1], [SubtaskProgress::of($root)->done, SubtaskProgress::of($root)->total]);
        $tree = TaskTree::fromTasks($chain);
        self::assertCount(self::DEEP - 1, $tree->descendantsOf($root));
        self::assertSame(self::DEEP - 1, $tree->progress($root)->total);

        $flatten = new ReflectionMethod(TaskGanttBuilder::class, 'flatten');
        $flat    = $flatten->invoke(new TaskGanttBuilder(), $chain);
        self::assertCount(self::DEEP, $flat);
        self::assertSame(self::DEEP - 1, $flat[self::DEEP - 1]['depth']);

        $nodes = (new TaskBoardTwigExtension())->subtaskNodes([$root], $tree);
        self::assertSame(self::DEEP - 1, $nodes[0]['progress']->total, 'every level counts');
        self::assertSame(TaskBoardTwigExtension::SUBTASK_TREE_LEVELS, $this->levels($nodes[0]), 'the page nests a bounded number of levels');
    }

    public function testTheSubtreeOfAMoveOrACopyIsBoundedByTheSafetyCap(): void
    {
        [$root] = $this->chain(TaskTreeLimits::SAFETY_CAP + 2);

        try {
            TaskMovePlanner::subtree($root);
            self::fail('Refused expected.');
        } catch (TaskCopyRefusedException $refused) {
            self::assertSame('too_deep', $refused->reason);
        }
    }

    public function testALoopEndsTheReadsAndRefusesTheWrites(): void
    {
        $a = new Task($this->board, 'A', new stdClass());
        $b = new Task($this->board, 'B', new stdClass(), parent: $a);
        $a->getSubtasks()->add($b);
        (new ReflectionProperty(Task::class, 'parent'))->setValue($a, $b);
        $b->getSubtasks()->add($a);

        self::assertSame(1, SubtaskProgress::of($a)->total);
        $nodes = (new TaskBoardTwigExtension())->subtaskNodes([$a]);
        self::assertSame(1, $nodes[0]['progress']->total);
        self::assertCount(1, $nodes[0]['children']);
        self::assertSame([], $nodes[0]['children'][0]['children'], 'A is not shown under B again');
        $flatten = new ReflectionMethod(TaskGanttBuilder::class, 'flatten');
        $c       = new Task($this->board, 'C', new stdClass());
        $d       = new Task($this->board, 'D', new stdClass(), parent: $c);
        self::assertCount(2, $flatten->invoke(new TaskGanttBuilder(), [$c, $d, $d]), 'a task listed twice is drawn once');

        $this->expectException(TaskCopyRefusedException::class);
        TaskMovePlanner::subtree($a);
    }

    public function testTheLevelWalksOfAMoveFailClosed(): void
    {
        $seen = ['a' => true];
        self::assertSame(['b', '1'], TaskMovePlanner::nextLevel(['b', 1], $seen, 1));
        foreach ([[['a'], 2, 'tree_loop'], [['c'], TaskTreeLimits::SAFETY_CAP + 1, 'too_deep']] as [$ids, $levels, $reason]) {
            try {
                TaskMovePlanner::nextLevel($ids, $seen, $levels);
                self::fail('Refused expected: ' . $reason);
            } catch (TaskCopyRefusedException $refused) {
                self::assertSame($reason, $refused->reason);
            }
        }
    }

    public function testTheCopyWalkFailsClosed(): void
    {
        $visibleTree = new ReflectionMethod(TaskCopier::class, 'visibleTree');
        $copier      = (new ReflectionClass(TaskCopier::class))->newInstanceWithoutConstructor();
        [$root]      = $this->chain(TaskTreeLimits::SAFETY_CAP + 2);
        $a           = new Task($this->board, 'A', new stdClass());
        $b           = new Task($this->board, 'B', new stdClass(), parent: $a);
        $a->getSubtasks()->add($b);
        $b->getSubtasks()->add($a);

        foreach ([[$root, 'too_deep'], [$a, 'tree_loop']] as [$source, $reason]) {
            try {
                $visibleTree->invoke($copier, $source, new TaskCopyOptions(), null);
                self::fail('Refused expected: ' . $reason);
            } catch (TaskCopyRefusedException $refused) {
                self::assertSame($reason, $refused->reason);
            }
        }
    }

    public function testAHandBuiltManagerChecksTheLoadedTree(): void
    {
        $repository     = $this->createMock(TaskRepositoryInterface::class);
        $manager        = new TaskManager($repository, new TaskChangeRecorder(), treeLimits: new TaskTreeLimits(1));
        [$root, $chain] = $this->chain(2);

        self::assertSame($root, $manager->create($this->board, new TaskFormData('Fits'), new stdClass(), $root)->getParent());
        $this->expectException(TaskTreeRefusedException::class);
        $manager->create($this->board, new TaskFormData('Too deep'), new stdClass(), $chain[1]);
    }

    /**
     * @return array{0: Task, 1: list<Task>}
     */
    private function chain(int $length): array
    {
        $chain  = [];
        $parent = null;
        for ($i = 0; $i < $length; ++$i) {
            $task = new Task($this->board, 'Level ' . $i, new stdClass(), parent: $parent);
            $parent?->getSubtasks()->add($task);
            $chain[] = $task;
            $parent  = $task;
        }

        return [$chain[0], $chain];
    }

    /**
     * @param array<string, mixed> $node
     */
    private function levels(array $node): int
    {
        $levels = 1;
        while (($node['children'] ?? []) !== []) {
            ++$levels;
            $node = $node['children'][0];
        }

        return $levels;
    }
}
