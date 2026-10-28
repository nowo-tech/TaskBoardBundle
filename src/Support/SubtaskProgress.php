<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Support;

use Nowo\TaskBoardBundle\Entity\Task;

/**
 * Done / total of a task's subtasks at any depth (since 1.10): every descendant counts once.
 */
final readonly class SubtaskProgress
{
    /**
     * @param int $done Completed descendants
     * @param int $total All descendants
     */
    public function __construct(
        public int $done = 0,
        public int $total = 0,
    ) {
    }

    /**
     * The progress of `$task`'s subtree (the task itself excluded).
     */
    public static function of(Task $task): self
    {
        $done  = 0;
        $total = 0;
        foreach ($task->getSubtasks() as $subtask) {
            $nested = self::of($subtask);
            $total += 1 + $nested->total;
            $done += ($subtask->isCompleted() ? 1 : 0) + $nested->done;
        }

        return new self($done, $total);
    }

    /**
     * Completed share in percent (0 – 100, rounded down); 0 without subtasks.
     */
    public function percent(): int
    {
        return $this->total === 0 ? 0 : intdiv($this->done * 100, $this->total);
    }
}
