<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Service;

use Nowo\TaskBoardBundle\Dto\TaskColumnNavigation;
use Nowo\TaskBoardBundle\Dto\TaskFormData;
use Nowo\TaskBoardBundle\Entity\BoardColumn;
use Nowo\TaskBoardBundle\Entity\Task;
use Nowo\TaskBoardBundle\Entity\TaskBoard;
use Nowo\TaskBoardBundle\Enum\TaskChangeType;
use Nowo\TaskBoardBundle\Enum\TaskPriority;
use Nowo\TaskBoardBundle\Event\AbstractTaskEvent;
use Nowo\TaskBoardBundle\Event\TaskBoardEvents;
use Nowo\TaskBoardBundle\Event\TaskCompletedEvent;
use Nowo\TaskBoardBundle\Event\TaskCreatedEvent;
use Nowo\TaskBoardBundle\Event\TaskMovedEvent;
use Nowo\TaskBoardBundle\Event\TaskReopenedEvent;
use Nowo\TaskBoardBundle\Event\TaskUpdatedEvent;
use Nowo\TaskBoardBundle\Exception\ColumnNotFoundException;
use Nowo\TaskBoardBundle\Repository\TaskRepositoryInterface;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

use function array_filter;
use function array_keys;
use function count;

use const DATE_ATOM;

/**
 * Creates and updates tasks on a board; after each saved write it dispatches the task events (since 1.10:
 * {@see TaskBoardEvents::TASK_CREATED}, TASK_UPDATED, TASK_MOVED, TASK_COMPLETED, TASK_REOPENED).
 */
final readonly class TaskManager
{
    /**
     * @param EventDispatcherInterface|null $eventDispatcher Dispatches the task events (none: no events)
     */
    public function __construct(
        private TaskRepositoryInterface $taskRepository,
        private TaskChangeRecorder $changeRecorder,
        private ?EventDispatcherInterface $eventDispatcher = null,
    ) {
    }

    public function create(TaskBoard $board, TaskFormData $data, object $creator, ?Task $parent = null): Task
    {
        $column = $this->resolveColumn($board, $data->columnId);

        $task = new Task(
            board: $board,
            title: $data->title,
            creator: $creator,
            column: $column,
            parent: $parent,
            description: $data->description,
            priority: $data->priority,
            position: $this->nextPosition($board, $column),
            estimatedMinutes: $data->estimatedMinutes,
            dueAt: $data->dueAt,
            tags: $data->tags,
        );

        $this->changeRecorder->recordCreated($task, $creator);
        $this->taskRepository->save($task);
        $this->dispatch(new TaskCreatedEvent($task, $creator), TaskBoardEvents::TASK_CREATED);

        return $task;
    }

    public function update(Task $task, TaskFormData $data, object $user): void
    {
        $oldTitle            = $task->getTitle();
        $oldDescription      = $task->getDescription();
        $oldPriority         = $task->getPriority();
        $oldColumn           = $task->getColumn();
        $oldEstimatedMinutes = $task->getEstimatedMinutes();
        $oldDueAt            = $task->getDueAt();
        $oldTags             = $task->getTags();

        $newColumn = $this->resolveColumn($task->getBoard(), $data->columnId);

        $task
            ->setTitle($data->title)
            ->setDescription($data->description)
            ->setPriority($data->priority)
            ->setColumn($newColumn)
            ->setEstimatedMinutes($data->estimatedMinutes)
            ->setDueAt($data->dueAt)
            ->setTags($data->tags);

        $this->changeRecorder->recordIfChanged($task, $user, TaskChangeType::Title, $oldTitle, $data->title);
        $this->changeRecorder->recordIfChanged($task, $user, TaskChangeType::Description, $oldDescription, $data->description);
        $this->changeRecorder->recordIfChanged($task, $user, TaskChangeType::Priority, $oldPriority, $data->priority);
        $this->changeRecorder->recordIfChanged($task, $user, TaskChangeType::Column, $oldColumn, $newColumn);
        $this->changeRecorder->recordIfChanged($task, $user, TaskChangeType::EstimatedMinutes, $oldEstimatedMinutes, $data->estimatedMinutes);
        $this->changeRecorder->recordIfChanged($task, $user, TaskChangeType::DueDate, $oldDueAt, $data->dueAt);
        $this->changeRecorder->recordIfChanged($task, $user, TaskChangeType::Tags, $oldTags, $task->getTags());

        $this->taskRepository->save($task);

        $changed = array_keys(array_filter([
            'title'            => $oldTitle !== $task->getTitle(),
            'description'      => $oldDescription !== $task->getDescription(),
            'priority'         => $oldPriority !== $task->getPriority(),
            'column'           => $oldColumn?->getId() !== $newColumn?->getId(),
            'estimatedMinutes' => $oldEstimatedMinutes !== $task->getEstimatedMinutes(),
            'dueAt'            => $oldDueAt?->format(DATE_ATOM) !== $task->getDueAt()?->format(DATE_ATOM),
            'tags'             => $oldTags !== $task->getTags(),
        ]));
        if ($changed !== []) {
            $this->dispatch(new TaskUpdatedEvent($task, $user, $changed), TaskBoardEvents::TASK_UPDATED);
        }
        if ($oldColumn?->getId() !== $newColumn?->getId()) {
            $this->dispatch(new TaskMovedEvent($task, $user, $oldColumn, $newColumn), TaskBoardEvents::TASK_MOVED);
        }
    }

    public function updatePriority(Task $task, TaskPriority $priority, object $user): void
    {
        $oldPriority = $task->getPriority();
        $task->setPriority($priority);
        $this->changeRecorder->recordIfChanged($task, $user, TaskChangeType::Priority, $oldPriority, $priority);
        $this->taskRepository->save($task);
        if ($oldPriority !== $priority) {
            $this->dispatch(new TaskUpdatedEvent($task, $user, ['priority']), TaskBoardEvents::TASK_UPDATED);
        }
    }

    /**
     * @throws ColumnNotFoundException when $columnId is not a column of the task's board
     */
    /**
     * Moves a task to a column at the given index and renumbers the target column (0..n-1) so
     * client-computed positions (e.g. drag & drop card counts) never collide with existing ones.
     */
    public function move(Task $task, ?string $columnId, int $position, object $user): void
    {
        $this->applyMove($task, $columnId, $position, $user, true);
    }

    private function applyMove(Task $task, ?string $columnId, int $position, object $user, bool $renumberSiblings): void
    {
        $columns      = $this->orderedColumns($task->getBoard());
        $oldColumn    = $task->getColumn();
        $wasCompleted = $task->isCompleted();
        $column       = $this->resolveColumn($task->getBoard(), $columnId);

        if ($columnId !== null && $columnId !== '' && !$column instanceof BoardColumn) {
            throw ColumnNotFoundException::forBoard($columnId, $task->getBoard()->getId());
        }

        $task
            ->setColumn($column)
            ->setPosition($position);

        if ($renumberSiblings) {
            $this->renumberColumn($task, $column, $position);
        }

        $this->syncCompletionForColumn($task, $column, $columns);
        $this->changeRecorder->recordIfChanged($task, $user, TaskChangeType::Column, $oldColumn, $column);

        if ($wasCompleted !== $task->isCompleted()) {
            $this->changeRecorder->recordIfChanged($task, $user, TaskChangeType::Completed, $wasCompleted, $task->isCompleted());
        }

        $this->taskRepository->save($task);

        if ($oldColumn?->getId() !== $column?->getId()) {
            $this->dispatch(new TaskMovedEvent($task, $user, $oldColumn, $column), TaskBoardEvents::TASK_MOVED);
        }
        if (!$wasCompleted && $task->isCompleted()) {
            $this->dispatch(new TaskCompletedEvent($task, $user), TaskBoardEvents::TASK_COMPLETED);
        } elseif ($wasCompleted && !$task->isCompleted()) {
            $this->dispatch(new TaskReopenedEvent($task, $user), TaskBoardEvents::TASK_REOPENED);
        }
    }

    /**
     * Dispatches a task event (after the write was saved) when a dispatcher is wired.
     */
    private function dispatch(AbstractTaskEvent $event, string $eventName): void
    {
        $this->eventDispatcher?->dispatch($event, $eventName);
    }

    public function getColumnNavigation(Task $task): TaskColumnNavigation
    {
        $columns = $this->orderedColumns($task->getBoard());
        $index   = $this->currentColumnIndex($task, $columns);
        $last    = count($columns) - 1;

        $current  = $index >= 0 ? $columns[$index] : null;
        $previous = $index > 0 ? $columns[$index - 1] : null;
        $next     = $index >= 0 && $index < $last ? $columns[$index + 1] : ($index < 0 && $columns !== [] ? $columns[0] : null);
        $done     = $columns !== [] ? $columns[$last] : null;

        return new TaskColumnNavigation(
            currentColumnName: $current?->getName(),
            previousColumnName: $previous?->getName(),
            nextColumnName: $next?->getName(),
            doneColumnName: $done?->getName(),
            canPrevious: $index > 0,
            canNext: $next !== null && ($index < 0 || $index < $last),
            canDone: $done !== null && ($index < $last || !$task->isCompleted()),
            isCompleted: $task->isCompleted(),
        );
    }

    public function moveToPreviousColumn(Task $task, object $user): bool
    {
        $columns = $this->orderedColumns($task->getBoard());
        $index   = $this->currentColumnIndex($task, $columns);

        if ($index <= 0) {
            return false;
        }

        $target = $columns[$index - 1];
        $this->applyMove($task, $target->getId(), $this->nextPosition($task->getBoard(), $target), $user, false);

        return true;
    }

    public function moveToNextColumn(Task $task, object $user): bool
    {
        $columns = $this->orderedColumns($task->getBoard());
        $index   = $this->currentColumnIndex($task, $columns);
        $target  = $index < 0 ? ($columns[0] ?? null) : ($columns[$index + 1] ?? null);

        if ($target === null) {
            return false;
        }

        $this->applyMove($task, $target->getId(), $this->nextPosition($task->getBoard(), $target), $user, false);

        return true;
    }

    public function moveToDone(Task $task, object $user): bool
    {
        $columns = $this->orderedColumns($task->getBoard());
        if ($columns === []) {
            return false;
        }

        $done = $columns[array_key_last($columns)];
        $this->applyMove($task, $done->getId(), $this->nextPosition($task->getBoard(), $done), $user, false);

        return true;
    }

    public function moveToColumn(Task $task, string $columnId, object $user): bool
    {
        if ($columnId === '') {
            return false;
        }

        $column = $this->resolveColumn($task->getBoard(), $columnId);
        if (!$column instanceof BoardColumn || $task->getColumn()?->getId() === $columnId) {
            return false;
        }

        $this->applyMove($task, $columnId, $this->nextPosition($task->getBoard(), $column), $user, false);

        return true;
    }

    /** @return list<BoardColumn> */
    private function orderedColumns(TaskBoard $board): array
    {
        return array_values($board->getColumns()->toArray());
    }

    /**
     * @param list<BoardColumn> $columns
     */
    private function currentColumnIndex(Task $task, array $columns): int
    {
        $current = $task->getColumn();
        if (!$current instanceof BoardColumn) {
            return -1;
        }

        foreach ($columns as $index => $column) {
            if ($column->getId() === $current->getId()) {
                return $index;
            }
        }

        return -1;
    }

    /**
     * @param list<BoardColumn> $columns
     */
    private function syncCompletionForColumn(Task $task, ?BoardColumn $column, array $columns): void
    {
        if ($columns === [] || !$column instanceof BoardColumn) {
            return;
        }

        $lastColumn = $columns[array_key_last($columns)];
        if ($column->getId() === $lastColumn->getId()) {
            $task->markCompleted();

            return;
        }

        if ($task->isCompleted()) {
            $task->markIncomplete();
        }
    }

    private function resolveColumn(TaskBoard $board, ?string $columnId): ?BoardColumn
    {
        if ($columnId === null || $columnId === '') {
            return $board->getColumns()->first() ?: null;
        }

        foreach ($board->getColumns() as $column) {
            if ($column->getId() === $columnId) {
                return $column;
            }
        }

        return null;
    }

    /**
     * Inserts $task at index $position among the other tasks of $column and rewrites positions as 0..n-1.
     * Siblings whose position changes are saved; the moved task is saved by the caller.
     */
    private function renumberColumn(Task $task, ?BoardColumn $column, int $position): void
    {
        $siblings = [];
        foreach ($this->taskRepository->findByBoard($task->getBoard(), true) as $candidate) {
            if ($candidate->getId() === $task->getId()) {
                continue;
            }

            if ($candidate->getColumn()?->getId() === $column?->getId()) {
                $siblings[] = $candidate;
            }
        }

        usort($siblings, static fn (Task $a, Task $b): int => $a->getPosition() <=> $b->getPosition());

        $index = max(0, min($position, count($siblings)));
        array_splice($siblings, $index, 0, [$task]);

        foreach ($siblings as $newPosition => $sibling) {
            if ($sibling->getPosition() === $newPosition) {
                continue;
            }

            $sibling->setPosition($newPosition);
            if ($sibling !== $task) {
                $this->taskRepository->save($sibling);
            }
        }
    }

    private function nextPosition(TaskBoard $board, ?BoardColumn $column): int
    {
        $max = -1;
        foreach ($this->taskRepository->findByBoard($board, true) as $task) {
            if (!$column instanceof BoardColumn || ($task->getColumn()?->getId() === $column->getId())) {
                $max = max($max, $task->getPosition());
            }
        }

        return $max + 1;
    }
}
