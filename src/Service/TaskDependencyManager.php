<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Service;

use Nowo\TaskBoardBundle\Entity\Task;
use Nowo\TaskBoardBundle\Entity\TaskBoardFolder;
use Nowo\TaskBoardBundle\Entity\TaskDependency;
use Nowo\TaskBoardBundle\Enum\TaskChangeType;
use Nowo\TaskBoardBundle\Enum\TaskDependencyType;
use Nowo\TaskBoardBundle\Event\TaskBoardEvents;
use Nowo\TaskBoardBundle\Event\TaskDependencyAddedEvent;
use Nowo\TaskBoardBundle\Event\TaskDependencyRemovedEvent;
use Nowo\TaskBoardBundle\Exception\InvalidTaskDependencyException;
use Nowo\TaskBoardBundle\Folder\TaskBoardDependencyScope;
use Nowo\TaskBoardBundle\Folder\TaskBoardFolderManager;
use Nowo\TaskBoardBundle\Repository\TaskDependentsLookupInterface;
use Nowo\TaskBoardBundle\Repository\TaskRepositoryInterface;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

use function array_pop;

/**
 * Since 1.15: adds and removes the dependencies between two tasks of a board, from the task detail or panel.
 *
 * A dependency is refused on the task itself, towards a task of another board, when the same link already exists
 * (in either direction for "blocks" / "blocked by" and "related"), and when it would close a cycle of "blocks" links
 * (A blocks B blocks … blocks A). Each change is recorded in the task's history. Since 2.11.0 (DDD audit G3): after each
 * saved change it dispatches {@see TaskBoardEvents::TASK_DEPENDENCY_ADDED} / {@see TaskBoardEvents::TASK_DEPENDENCY_REMOVED}
 * (ids only), through {@see TaskEventDeferral}: held until the commit of a sync page or an import.
 */
final readonly class TaskDependencyManager
{
    public function __construct(
        private TaskRepositoryInterface $taskRepository,
        private TaskChangeRecorder $changeRecorder,
        // Since 2.11.0 (DDD audit G3): dispatches TASK_DEPENDENCY_ADDED / TASK_DEPENDENCY_REMOVED (none: no event).
        private ?EventDispatcherInterface $eventDispatcher = null,
        // Since 2.19.0 (Beacon spec 234, FR-032): two boards of one folder may be joined.
        private TaskBoardDependencyScope $scope = new TaskBoardDependencyScope(),
        private ?TaskBoardFolderManager $folders = null,
        // Since 2.16.0 (spec 234): the reverse lookup of blocked_by (none: the task's loaded relations).
        private ?TaskDependentsLookupInterface $dependents = null,
    ) {
    }

    /**
     * Since 2.16.0 (spec 234): the tasks that wait on `$task` (blocked by it), of its tenant only.
     *
     * @return list<Task>
     */
    public function dependentsOf(Task $task): array
    {
        if ($this->dependents instanceof TaskDependentsLookupInterface) {
            return $this->dependents->dependentsOf($task);
        }
        $tenant = $task->getBoard()->getTenantKey();
        $found  = [];
        foreach ([...$task->getIncomingDependencies(), ...$task->getOutgoingDependencies()] as $dependency) {
            $waits = $dependency->getTargetTask() === $task ? $dependency->getDependencyType() === TaskDependencyType::BlockedBy : $dependency->getDependencyType() === TaskDependencyType::Blocks;
            $other = $dependency->getTargetTask() === $task ? $dependency->getSourceTask() : $dependency->getTargetTask();
            if ($waits && $other !== $task && $other->getBoard()->getTenantKey() === $tenant) {
                $found[$other->getId()] = $other;
            }
        }

        return array_values($found);
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

        // Since 2.19.0 (FR-032): the same board, or two boards of the same folder; any other pair stays refused.
        if (!$this->scope->allows($task->getBoard(), $other->getBoard())) {
            throw InvalidTaskDependencyException::otherBoard();
        }

        $boardTasks   = $this->scopeTasks($task, $other);
        $boardTasks[] = $task;
        $boardTasks[] = $other;
        $existing     = $this->dependenciesOf($boardTasks);
        $key          = $this->identity($task, $other, $type);

        foreach ($existing as $dependency) {
            if ($this->identity($dependency->getSourceTask(), $dependency->getTargetTask(), $dependency->getDependencyType()) === $key) {
                throw InvalidTaskDependencyException::duplicate();
            }
        }

        $edge = $this->blockingEdge($task, $other, $type);
        if ($edge !== null && $this->reaches($existing, $edge[1], $edge[0])) {
            throw InvalidTaskDependencyException::cycle();
        }

        $dependency = new TaskDependency($task, $other, $type);
        $task->addOutgoingDependency($dependency);
        $this->changeRecorder->record($task, $user, TaskChangeType::DependencyAdded, null, $other->getTitle(), $type->relation());
        $this->taskRepository->save($task);
        $this->dispatchSaved(TaskDependencyAddedEvent::of($task, $dependency, $user, $this->changeRecorder->currentCause()), TaskBoardEvents::TASK_DEPENDENCY_ADDED);

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

            $event = TaskDependencyRemovedEvent::of($task, $dependency, $user, $this->changeRecorder->currentCause());
            $task->removeDependency($dependency);
            $this->changeRecorder->record($task, $user, TaskChangeType::DependencyRemoved, $other->getTitle(), null, $relation);
            $this->taskRepository->save($task);
            $this->dispatchSaved($event, TaskBoardEvents::TASK_DEPENDENCY_REMOVED);

            return true;
        }

        return false;
    }

    /**
     * Since 2.2.0 (spec 213): the dependencies joining the two tasks, in either direction.
     *
     * @return list<TaskDependency>
     */
    public function findBetween(Task $task, Task $other): array
    {
        $found = [];
        foreach ([...$task->getOutgoingDependencies(), ...$task->getIncomingDependencies()] as $dependency) {
            if ($dependency->getSourceTask()->getId() === $other->getId() || $dependency->getTargetTask()->getId() === $other->getId()) {
                $found[] = $dependency;
            }
        }

        return $found;
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
            $edge = $this->blockingEdge($dependency->getSourceTask(), $dependency->getTargetTask(), $dependency->getDependencyType());
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
    private function blockingEdge(Task $source, Task $target, TaskDependencyType $type): ?array
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
    private function identity(Task $source, Task $target, TaskDependencyType $type): string
    {
        $edge = $this->blockingEdge($source, $target, $type);
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

    /** Since 2.11.0: the event of a saved write; a listener failure is logged, not rethrown (audit KO-T-L2). */
    private function dispatchSaved(object $event, string $eventName): void
    {
        if ($this->eventDispatcher instanceof TaskEventDeferral) {
            $this->eventDispatcher->dispatchSaved($event, $eventName);

            return;
        }
        $this->eventDispatcher?->dispatch($event, $eventName);
    }

    /**
     * Since 2.19.0 (FR-032): the tasks a cycle may run through — the board's, or every board's of the folder when the two
     * tasks are on different boards of it.
     *
     * @return list<Task>
     */
    private function scopeTasks(Task $task, Task $other): array
    {
        $board  = $task->getBoard();
        $folder = $board->getFolder();
        $inside = $folder instanceof TaskBoardFolder && $this->folders instanceof TaskBoardFolderManager;
        // Audit PW-L3: a same-board pair of a board in a folder may close a cycle through another board of the folder, so
        // the whole folder is read then too.
        if ($board->getId() === $other->getBoard()->getId() && !$inside) {
            return $this->taskRepository->findByBoard($board, true);
        }
        $boards = $folder instanceof TaskBoardFolder && $this->folders instanceof TaskBoardFolderManager ? $this->folders->boardsIn($folder, true) : [$board, $other->getBoard()];
        $tasks  = [];
        foreach ($boards as $member) {
            foreach ($this->taskRepository->findByBoard($member, true) as $candidate) {
                $tasks[] = $candidate;
            }
        }

        return $tasks;
    }
}
