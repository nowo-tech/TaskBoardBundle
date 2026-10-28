<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Support;

use Nowo\TaskBoardBundle\Entity\Task;

/**
 * Parent / children lookup over tasks already loaded (e.g. every task of a board in one query), so a subtask tree of
 * any depth and its done / total counters render without a query per node (since 1.10).
 */
final readonly class TaskTree
{
    /**
     * @param array<string, list<Task>> $children Task id => its subtasks, in the given order
     * @param list<Task> $roots Tasks whose parent is not among the given tasks
     */
    private function __construct(
        private array $children,
        private array $roots,
    ) {
    }

    /**
     * Builds the tree of `$tasks` (their order is kept among siblings).
     *
     * @param iterable<Task> $tasks
     */
    public static function fromTasks(iterable $tasks): self
    {
        $byId = [];
        foreach ($tasks as $task) {
            $byId[$task->getId()] = $task;
        }

        $children = [];
        $roots    = [];
        foreach ($byId as $task) {
            $parentId = $task->getParent()?->getId();
            if ($parentId !== null && isset($byId[$parentId])) {
                $children[$parentId][] = $task;
            } else {
                $roots[] = $task;
            }
        }

        return new self($children, $roots);
    }

    /**
     * Tasks without a parent among the loaded tasks (top-level tasks for a whole board).
     *
     * @return list<Task>
     */
    public function roots(): array
    {
        return $this->roots;
    }

    /**
     * The direct subtasks of `$task` among the loaded tasks.
     *
     * @return list<Task>
     */
    public function childrenOf(Task $task): array
    {
        return $this->children[$task->getId()] ?? [];
    }

    /**
     * Since 1.15: `$task`'s subtasks at any depth among the loaded tasks (depth-first, in order).
     *
     * @return list<Task>
     */
    public function descendantsOf(Task $task): array
    {
        $descendants = [];
        foreach ($this->childrenOf($task) as $child) {
            $descendants[] = $child;
            foreach ($this->descendantsOf($child) as $nested) {
                $descendants[] = $nested;
            }
        }

        return $descendants;
    }

    /**
     * Done / total of `$task`'s subtasks at any depth among the loaded tasks.
     */
    public function progress(Task $task): SubtaskProgress
    {
        $done  = 0;
        $total = 0;
        foreach ($this->childrenOf($task) as $child) {
            $nested = $this->progress($child);
            $total += 1 + $nested->total;
            $done += ($child->isCompleted() ? 1 : 0) + $nested->done;
        }

        return new SubtaskProgress($done, $total);
    }
}
