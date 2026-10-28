<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Service;

use Nowo\TaskBoardBundle\Entity\Task;
use Nowo\TaskBoardBundle\Entity\TaskDependency;
use Nowo\TaskBoardBundle\Enum\TaskChangeType;
use Nowo\TaskBoardBundle\Enum\TaskDependencyType;
use Nowo\TaskBoardBundle\Exception\InvalidTaskDependencyException;
use Nowo\TaskBoardBundle\Repository\TaskRepositoryInterface;

use function array_pop;

/**
 * Since 1.15: adds and removes the dependencies between two tasks of a board, from the task detail or panel.
 *
 * A dependency is refused on the task itself, towards a task of another board, when the same link already exists
 * (in either direction for "blocks" / "blocked by" and "related"), and when it would close a cycle of "blocks" links
 * (A blocks B blocks … blocks A). Each change is recorded in the task's history.
 */
final readonly class TaskDependencyManager
{
    public function __construct(
        private TaskRepositoryInterface $taskRepository,
        private TaskChangeRecorder $changeRecorder,
    ) {
    }

    /**
     * Adds "`$task` <type> `$other`" (e.g. $task blocks $other).
     *
     * @throws InvalidTaskDependencyException
     */
    public function add(Task $task, Task $other, TaskDependencyType $type, object $user): TaskDependency
    {
        if ($task->getId() === $other->getId()) {
            throw InvalidTaskDependencyException::self();
        }

        if ($task->getBoard()->getId() !== $other->getBoard()->getId()) {
            throw InvalidTaskDependencyException::otherBoard();
        }

        $boardTasks   = $this->taskRepository->findByBoard($task->getBoard(), true);
        $boardTasks[] = $task;
        $boardTasks[] = $other;
        $existing     = $this->dependenciesOf($boardTasks);
        $key          = self::identity($task, $other, $type);

        foreach ($existing as $dependency) {
            if (self::identity($dependency->getSourceTask(), $dependency->getTargetTask(), $dependency->getDependencyType()) === $key) {
                throw InvalidTaskDependencyException::duplicate();
            }
        }

        $edge = self::blockingEdge($task, $other, $type);
        if ($edge !== null && $this->reaches($existing, $edge[1], $edge[0])) {
            throw InvalidTaskDependencyException::cycle();
        }

        $dependency = new TaskDependency($task, $other, $type);
        $task->addOutgoingDependency($dependency);
        $this->changeRecorder->record($task, $user, TaskChangeType::DependencyAdded, null, $other->getTitle(), $type->relation());
        $this->taskRepository->save($task);

        return $dependency;
    }

    /**
     * Removes one of the task's dependencies (outgoing or incoming); false when the task has none with that id.
     */
    public function remove(Task $task, string $dependencyId, object $user): bool
    {
        foreach ([...$task->getOutgoingDependencies(), ...$task->getIncomingDependencies()] as $dependency) {
            if ($dependency->getId() !== $dependencyId) {
                continue;
            }

            $outgoing = $dependency->getSourceTask() === $task;
            $other    = $outgoing ? $dependency->getTargetTask() : $dependency->getSourceTask();
            $relation = $dependency->getDependencyType()->relation(!$outgoing);

            $task->removeDependency($dependency);
            $this->changeRecorder->record($task, $user, TaskChangeType::DependencyRemoved, $other->getTitle(), null, $relation);
            $this->taskRepository->save($task);

            return true;
        }

        return false;
    }

    /**
     * Every dependency of the given tasks, once.
     *
     * @param list<Task> $tasks
     *
     * @return array<string, TaskDependency>
     */
    private function dependenciesOf(array $tasks): array
    {
        $dependencies = [];
        foreach ($tasks as $task) {
            foreach ($task->getOutgoingDependencies() as $dependency) {
                $dependencies[$dependency->getId()] = $dependency;
            }
        }

        return $dependencies;
    }

    /**
     * Whether `$to` can be reached from `$from` following "blocks" edges (blocker → blocked).
     *
     * @param array<string, TaskDependency> $dependencies
     */
    private function reaches(array $dependencies, Task $from, Task $to): bool
    {
        /** @var array<string, list<string>> $next */
        $next = [];
        foreach ($dependencies as $dependency) {
            $edge = self::blockingEdge($dependency->getSourceTask(), $dependency->getTargetTask(), $dependency->getDependencyType());
            if ($edge !== null) {
                $next[$edge[0]->getId()][] = $edge[1]->getId();
            }
        }

        $stack   = [$from->getId()];
        $visited = [];
        while ($stack !== []) {
            $current = array_pop($stack);
            if ($current === $to->getId()) {
                return true;
            }

            if (isset($visited[$current])) {
                continue;
            }

            $visited[$current] = true;
            foreach ($next[$current] ?? [] as $target) {
                $stack[] = $target;
            }
        }

        return false;
    }

    /**
     * The "blocks" edge of a dependency as [blocker, blocked]; null for "related" and "duplicates".
     *
     * @return array{0: Task, 1: Task}|null
     */
    private static function blockingEdge(Task $source, Task $target, TaskDependencyType $type): ?array
    {
        return match ($type) {
            TaskDependencyType::Blocks    => [$source, $target],
            TaskDependencyType::BlockedBy => [$target, $source],
            default                       => null,
        };
    }

    /**
     * What makes two dependencies the same link: the blocker and the blocked task, the unordered pair of "related",
     * the ordered pair of "duplicates".
     */
    private static function identity(Task $source, Task $target, TaskDependencyType $type): string
    {
        $edge = self::blockingEdge($source, $target, $type);
        if ($edge !== null) {
            return 'blocks:' . $edge[0]->getId() . '>' . $edge[1]->getId();
        }

        if ($type === TaskDependencyType::Related) {
            $ids = [$source->getId(), $target->getId()];
            sort($ids);

            return 'related:' . $ids[0] . '~' . $ids[1];
        }

        return $type->value . ':' . $source->getId() . '>' . $target->getId();
    }
}
