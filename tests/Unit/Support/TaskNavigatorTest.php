<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Tests\Unit\Support;

use Nowo\TaskBoardBundle\Dto\TaskNavigation;
use Nowo\TaskBoardBundle\Entity\BoardColumn;
use Nowo\TaskBoardBundle\Entity\Task;
use Nowo\TaskBoardBundle\Entity\TaskBoard;
use Nowo\TaskBoardBundle\Support\TaskNavigator;
use Nowo\TaskBoardBundle\Support\TaskTree;
use PHPUnit\Framework\TestCase;
use stdClass;

final class TaskNavigatorTest extends TestCase
{
    private TaskBoard $board;

    private Task $todo1;

    private Task $todo2;

    private Task $doing1;

    private Task $sub1;

    private Task $sub2;

    private Task $sub3;

    private Task $loose;

    /** @var list<Task> */
    private array $tasks;

    protected function setUp(): void
    {
        $user        = new stdClass();
        $this->board = new TaskBoard('Board', 'board', $user);
        $todo        = new BoardColumn($this->board, 'To do', 0);
        $doing       = new BoardColumn($this->board, 'In progress', 1);
        $this->board->addColumn($todo);
        $this->board->addColumn($doing);

        $this->todo1  = new Task($this->board, 'Todo 1', $user, $todo);
        $this->todo2  = new Task($this->board, 'Todo 2', $user, $todo);
        $this->doing1 = new Task($this->board, 'Doing 1', $user, $doing);
        $this->sub1   = new Task($this->board, 'Sub 1', $user, $doing, $this->todo2);
        $this->sub2   = new Task($this->board, 'Sub 2', $user, $todo, $this->todo2);
        $this->sub3   = new Task($this->board, 'Sub 3', $user, null, $this->todo2);
        $this->loose  = new Task($this->board, 'Loose', $user);
        // findByBoard order (position): the subtasks in between.
        $this->tasks = [$this->todo1, $this->sub1, $this->todo2, $this->sub2, $this->doing1, $this->sub3, $this->loose];
    }

    public function testBoardOrderWalksColumnsLeftToRightAndJumpsToTheNextColumn(): void
    {
        $tree = TaskTree::fromTasks($this->tasks);

        self::assertSame([$this->todo1, $this->todo2, $this->doing1], TaskNavigator::sequence($this->todo1, $this->tasks, $tree, TaskNavigation::ORDER_BOARD, true));
        self::assertSame([$this->todo1, $this->todo2, $this->sub2, $this->sub1, $this->doing1], TaskNavigator::sequence($this->todo1, $this->tasks, $tree, TaskNavigation::ORDER_BOARD));

        $nav = TaskNavigator::navigate($this->todo2, $this->tasks, $tree, null, true);
        self::assertSame(TaskNavigation::ORDER_BOARD, $nav->order);
        self::assertSame([], $nav->query());
        self::assertSame([2, 3, 'To do'], [$nav->position?->index, $nav->position?->total, $nav->position?->group]);
        self::assertSame($this->todo1, $nav->position?->previous);
        // The end of "To do" goes on to the first card of "In progress".
        self::assertSame($this->doing1, $nav->position?->next);
        self::assertNull($nav->siblings);
        self::assertNull($nav->parent);
        self::assertSame($this->sub1, $nav->firstSubtask);
        self::assertFalse($nav->isEmpty());

        $last = TaskNavigator::navigate($this->doing1, $this->tasks, $tree, 'board', true);
        self::assertSame([3, 'In progress', null], [$last->position?->index, $last->position?->group, $last->position?->next]);
        self::assertNull(TaskNavigator::navigate($this->todo1, $this->tasks, $tree, 'board', true)->position?->previous);
    }

    public function testListOrderKeepsSubtasksAndEndsWithTasksWithoutColumn(): void
    {
        $tree = TaskTree::fromTasks($this->tasks);
        $nav  = TaskNavigator::navigate($this->loose, $this->tasks, $tree, 'list', true);

        self::assertSame(['from' => 'list'], $nav->query());
        self::assertSame([7, 7, null], [$nav->position?->index, $nav->position?->total, $nav->position?->group]);
        self::assertSame($this->sub3, $nav->position?->previous);
        self::assertNull($nav->position?->next);
        self::assertNull($nav->firstSubtask);
    }

    public function testSubtaskStepsThroughItsSiblings(): void
    {
        $tree = TaskTree::fromTasks($this->tasks);
        $nav  = TaskNavigator::navigate($this->sub2, $this->tasks, $tree, 'unknown', true);

        // Not on a board of top-level cards: no board position, the siblings instead.
        self::assertSame(TaskNavigation::ORDER_BOARD, $nav->order);
        self::assertNull($nav->position);
        self::assertSame([2, 3, null], [$nav->siblings?->index, $nav->siblings?->total, $nav->siblings?->group]);
        self::assertSame([$this->sub1, $this->sub3], [$nav->siblings?->previous, $nav->siblings?->next]);
        self::assertSame($this->todo2, $nav->parent);
        self::assertFalse($nav->isEmpty());

        // A lone task: nothing to show.
        $alone = new Task(new TaskBoard('Other', 'other', new stdClass()), 'Alone', new stdClass());
        self::assertTrue(TaskNavigator::navigate($alone, [$alone], TaskTree::fromTasks([$alone]))->isEmpty());
    }
}
