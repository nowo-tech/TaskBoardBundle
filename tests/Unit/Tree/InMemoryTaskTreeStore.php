<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Tests\Unit\Tree;

use Closure;
use Nowo\TaskBoardBundle\Entity\Task;
use Nowo\TaskBoardBundle\Tree\TaskTreeNode;
use Nowo\TaskBoardBundle\Tree\TaskTreeStoreInterface;

use function array_key_exists;

/**
 * A plain-PHP store over task objects: what a DDD host's double of the port looks like. `$rows` overrides a task's
 * committed parent (another process wrote it); `$locked` lists the ids in the order they were locked.
 */
final class InMemoryTaskTreeStore implements TaskTreeStoreInterface
{
    /** @var array<string, Task> */
    public array $tasks = [];

    /** @var array<string, string|null> */
    public array $rows = [];

    /** @var list<string> */
    public array $locked = [];

    /** @var list<string> */
    public array $saved = [];

    /** @var list<array{0: string, 1: string|null}> */
    public array $events = [];

    public int $transactions = 0;

    public function add(Task ...$tasks): void
    {
        foreach ($tasks as $task) {
            $this->tasks[$task->getId()] = $task;
        }
    }

    public function transactional(Closure $work): mixed
    {
        ++$this->transactions;

        return $work();
    }

    public function lockedNode(string $taskId): ?TaskTreeNode
    {
        $task = $this->tasks[$taskId] ?? null;
        if (!$task instanceof Task) {
            return null;
        }
        $this->locked[] = $taskId;

        return new TaskTreeNode($taskId, $task->getBoard()->getId(), array_key_exists($taskId, $this->rows) ? $this->rows[$taskId] : $task->getParent()?->getId(), $task->getRevision());
    }

    public function task(TaskTreeNode $node): Task
    {
        return $this->tasks[$node->id];
    }

    public function save(Task $task): void
    {
        $this->saved[] = $task->getId();
    }

    public function parentChanged(Task $task, object $actor, ?Task $previousParent): void
    {
        $this->events[] = [$task->getId(), $previousParent?->getId()];
    }
}
