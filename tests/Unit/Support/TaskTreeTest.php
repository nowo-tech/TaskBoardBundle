<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Tests\Unit\Support;

use Nowo\TaskBoardBundle\Entity\Task;
use Nowo\TaskBoardBundle\Entity\TaskBoard;
use Nowo\TaskBoardBundle\Support\SubtaskProgress;
use Nowo\TaskBoardBundle\Support\TaskTree;
use PHPUnit\Framework\TestCase;
use stdClass;

final class TaskTreeTest extends TestCase
{
    public function testFourLevelTreeAndRecursiveProgress(): void
    {
        $board = new TaskBoard('Board', 'board', new stdClass());
        $user  = new stdClass();
        $root  = new Task($board, 'Root', $user);
        $a     = new Task($board, 'A', $user, parent: $root);
        $b     = new Task($board, 'B', $user, parent: $root);
        $a1    = new Task($board, 'A1', $user, parent: $a);
        $a1x   = new Task($board, 'A1x', $user, parent: $a1);
        $root->getSubtasks()->add($a);
        $root->getSubtasks()->add($b);
        $a->getSubtasks()->add($a1);
        $a1->getSubtasks()->add($a1x);
        $b->markCompleted();
        $a1x->markCompleted();
        $other = new Task($board, 'Other', $user);

        $tree = TaskTree::fromTasks([$root, $a, $b, $a1, $a1x, $other]);

        self::assertSame([$root, $other], $tree->roots());
        self::assertSame([$a, $b], $tree->childrenOf($root));
        self::assertSame([], $tree->childrenOf($other));
        self::assertEquals(new SubtaskProgress(2, 4), $tree->progress($root));
        self::assertEquals(new SubtaskProgress(1, 2), $tree->progress($a));
        self::assertEquals(SubtaskProgress::of($root), $tree->progress($root));
        self::assertSame(50, SubtaskProgress::of($root)->percent());
        self::assertSame(0, SubtaskProgress::of($other)->percent());

        // A subtask whose parent was not loaded is a root of the partial tree.
        self::assertSame([$a1], TaskTree::fromTasks([$a1, $a1x])->roots());
    }
}
