<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Support;

use Nowo\TaskBoardBundle\Entity\Task;

use function array_pop;
use function intdiv;
use function spl_object_id;

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
        // Since 2.20.0 (DoS bound): iterative, each descendant once (a loop in the data is not followed twice).
        $done  = 0;
        $total = 0;
        $seen  = [spl_object_id($task) => true];
        $stack = $task->getSubtasks()->toArray();
        while ($stack !== []) {
            $subtask = array_pop($stack);
            if (isset($seen[spl_object_id($subtask)])) {
                continue;
            }
            $seen[spl_object_id($subtask)] = true;
            ++$total;
            $done += $subtask->isCompleted() ? 1 : 0;
            foreach ($subtask->getSubtasks() as $child) {
                $stack[] = $child;
            }
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
