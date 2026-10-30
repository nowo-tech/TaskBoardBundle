<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Support;

use Nowo\TaskBoardBundle\Dto\TaskNavigation;
use Nowo\TaskBoardBundle\Dto\TaskPosition;
use Nowo\TaskBoardBundle\Entity\Task;

use function array_search;
use function count;
use function in_array;
use function is_int;

/**
 * Since 1.20: the previous / next task of a task page, computed from the board's tasks already loaded (one query).
 *
 * The board order is the one the board draws: its columns by position, then each column's cards by position (only
 * top-level cards with `board.top_level_only`); tasks without a column are not on the board. The list order is the
 * list view's: the same column groups with every task (subtasks too), then the tasks without a column. A subtask also
 * steps through its parent's subtasks, and a task with subtasks opens its first one.
 */
final class TaskNavigator
{
    /**
     * @param list<Task> $boardTasks every task of the board, by position (TaskRepositoryInterface::findByBoard)
     */
    public static function navigate(Task $task, array $boardTasks, TaskTree $tree, ?string $order = null, bool $topLevelOnly = false): TaskNavigation
    {
        $order    = $order === TaskNavigation::ORDER_LIST ? TaskNavigation::ORDER_LIST : TaskNavigation::ORDER_BOARD;
        $parent   = $task->getParent();
        $siblings = $parent instanceof Task ? self::position($task, $tree->childrenOf($parent), false) : null;
        $children = $tree->childrenOf($task);

        return new TaskNavigation(
            $order,
            self::position($task, self::sequence($task, $boardTasks, $tree, $order, $topLevelOnly), true),
            $siblings,
            $siblings instanceof TaskPosition ? $parent : null,
            $children[0] ?? null,
        );
    }

    /**
     * The tasks of the view in the order it shows them.
     *
     * @param list<Task> $boardTasks
     *
     * @return list<Task>
     */
    public static function sequence(Task $task, array $boardTasks, TaskTree $tree, string $order, bool $topLevelOnly = false): array
    {
        $roots    = $tree->roots();
        $sequence = [];
        foreach ($task->getBoard()->getColumns() as $column) {
            foreach ($boardTasks as $candidate) {
                if ($candidate->getColumn()?->getId() !== $column->getId()) {
                    continue;
                }

                if ($order === TaskNavigation::ORDER_BOARD && $topLevelOnly && !in_array($candidate, $roots, true)) {
                    continue;
                }

                $sequence[] = $candidate;
            }
        }

        if ($order === TaskNavigation::ORDER_LIST) {
            foreach ($boardTasks as $candidate) {
                if ($candidate->getColumn() === null) {
                    $sequence[] = $candidate;
                }
            }
        }

        return $sequence;
    }

    /**
     * @param list<Task> $sequence
     * @param bool $withColumn the position names the task's column ("3/14 · In progress")
     */
    private static function position(Task $task, array $sequence, bool $withColumn): ?TaskPosition
    {
        $index = array_search($task, $sequence, true);
        if (!is_int($index)) {
            return null;
        }

        return new TaskPosition(
            $index + 1,
            count($sequence),
            $sequence[$index - 1] ?? null,
            $sequence[$index + 1] ?? null,
            $withColumn ? $task->getColumn()?->getName() : null,
        );
    }
}
