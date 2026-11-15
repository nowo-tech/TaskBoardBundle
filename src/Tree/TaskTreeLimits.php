<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Tree;

use InvalidArgumentException;
use Nowo\TaskBoardBundle\Entity\Task;

use function array_key_last;
use function count;
use function max;
use function min;
use function spl_object_id;

use const PHP_INT_MAX;

/**
 * Since 2.20.0 (Beacon spec 234, T004): how deep the task tree may grow (`max_subtask_depth`), and the bounded walks
 * over loaded tasks that every caller shares. Framework-free and stateless (worker mode).
 *
 * The **depth** of a task is the number of its ancestors: a top-level task is at depth 0, its subtasks at 1. With
 * `max_subtask_depth: 7` a task may have at most 7 ancestors. A write that would put a task deeper is refused; a tree
 * that is already deeper (saved before the limit) stays readable and editable, and a move that does not take it any
 * deeper is allowed. Null: no limit.
 *
 * DoS bound: every walk here is iterative, remembers what it saw, and stops after {@see self::SAFETY_CAP} levels, also
 * without a limit. A loop already in the data, or a chain past the cap, counts as infinitely deep: the write is refused
 * (fails closed), the read paths simply stop.
 */
final readonly class TaskTreeLimits
{
    /** The most levels any walk follows, with or without `max_subtask_depth`. */
    public const SAFETY_CAP = 10_000;

    /** Infinitely deep: a loop in the data, or past {@see self::SAFETY_CAP}. */
    public const UNBOUNDED = PHP_INT_MAX;

    public function __construct(public ?int $maxSubtaskDepth = null)
    {
        if ($maxSubtaskDepth !== null && $maxSubtaskDepth < 1) {
            throw new InvalidArgumentException('max_subtask_depth is at least 1, or null (no limit).');
        }
    }

    /** The deepest depth a write may reach: `max_subtask_depth`, or the safety cap without one. */
    public function maxDepth(): int
    {
        return min($this->maxSubtaskDepth ?? self::SAFETY_CAP, self::SAFETY_CAP);
    }

    /**
     * Whether a write that takes a task (or the deepest task of a moved subtree) from `$before` (null: a new task) to
     * `$after` is allowed: within the limit, or no deeper than it already was.
     */
    public function allows(int $after, ?int $before = null): bool
    {
        return $after <= $this->maxDepth() || ($before !== null && $after <= $before);
    }

    /**
     * One more level of a level-by-level walk down a tree (a move's locks and snapshot): the ids not seen yet, now
     * marked. A loop in the data (an id seen again) or a walk past {@see self::SAFETY_CAP} levels fails closed.
     *
     * @param list<int|string> $ids the level just read
     * @param array<string, true> $seen
     *
     * @throws TaskTreeRefusedException `descendant` on a loop, `too_deep` past the cap
     *
     * @return list<string>
     */
    public static function nextLevel(array $ids, array &$seen, int $levels): array
    {
        if ($levels > self::SAFETY_CAP) {
            throw TaskTreeRefusedException::tooDeep();
        }
        $level = [];
        foreach ($ids as $id) {
            if (isset($seen[(string) $id])) {
                throw TaskTreeRefusedException::descendant();
            }
            $seen[(string) $id] = true;
            $level[]            = (string) $id;
        }

        return $level;
    }

    /** Whether a new subtask of the loaded `$parent` stays within the limit. */
    public function allowsChildOf(Task $parent): bool
    {
        return $this->allowsTree(self::depthOf($parent), 1);
    }

    /**
     * Whether a new tree of `$height` levels below a top task at `$topDepth` stays within the limit (a copy).
     * {@see self::UNBOUNDED} for either is refused.
     */
    public function allowsTree(int $topDepth, int $height): bool
    {
        return $topDepth !== self::UNBOUNDED && $height !== self::UNBOUNDED && $this->allows($topDepth + $height);
    }

    /**
     * Whether moving the loaded `$task`, with its subtasks, under `$parent` is allowed: no limit, a place no deeper than
     * the task is now, or a subtree whose deepest task stays within the limit. Bounded walks.
     */
    public function allowsMove(Task $task, Task $parent): bool
    {
        if ($this->maxSubtaskDepth === null) {
            return true;
        }
        $depth = self::depthOf($parent);
        if ($depth === self::UNBOUNDED) {
            return false;
        }
        if ($depth + 1 <= self::depthOf($task)) {
            return true; // no deeper than it is
        }
        $allowedHeight = $this->maxDepth() - $depth - 1;

        return $allowedHeight >= 0 && self::heightOf($task, $allowedHeight) <= $allowedHeight;
    }

    /** The depth of a loaded task (its ancestors), or {@see self::UNBOUNDED}. */
    public static function depthOf(Task $task): int
    {
        $chain = self::chainOf($task);

        return $chain === null ? self::UNBOUNDED : count($chain) - 1;
    }

    /** The height of a loaded task's subtree (0: no subtasks), at most `$bound + 1`, or {@see self::UNBOUNDED} on a loop. */
    public static function heightOf(Task $task, int $bound = self::SAFETY_CAP): int
    {
        $seen   = [spl_object_id($task) => true];
        $level  = [$task];
        $height = 0;
        while ($level !== [] && $height <= $bound) {
            $next = [];
            foreach ($level as $node) {
                foreach ($node->getSubtasks() as $child) {
                    if (isset($seen[spl_object_id($child)])) {
                        return self::UNBOUNDED;
                    }
                    $seen[spl_object_id($child)] = true;
                    $next[]                      = $child;
                }
            }
            if ($next !== []) {
                ++$height;
            }
            $level = $next;
        }

        return $height;
    }

    /**
     * Where a new subtask of `$parent` may go: `$parent` itself when within the limit, else its nearest ancestor that is
     * (null: the top level). For batch writers (imports, provider sync) that place a row rather than refuse it.
     */
    public function allowedParent(Task $parent): ?Task
    {
        $chain = self::chainOf($parent);
        if ($chain === null) {
            return null;
        }
        // $chain: the parent, then its ancestors up to the root; the element at index $i has depth $last - $i, and a child
        // of it is allowed when $last - $i + 1 <= maxDepth(). The root (depth 0) always is: maxDepth() >= 1.
        $last = array_key_last($chain);

        return $chain[max(0, $last + 1 - $this->maxDepth())];
    }

    /**
     * The task then its ancestors, up to the root; null on a loop or a chain past {@see self::SAFETY_CAP}.
     *
     * @return list<Task>|null
     */
    private static function chainOf(Task $task): ?array
    {
        $chain = [$task];
        $seen  = [spl_object_id($task) => true];
        for ($node = $task->getParent(); $node instanceof Task; $node = $node->getParent()) {
            if (isset($seen[spl_object_id($node)]) || count($chain) > self::SAFETY_CAP) {
                return null;
            }
            $seen[spl_object_id($node)] = true;
            $chain[]                    = $node;
        }

        return $chain;
    }
}
