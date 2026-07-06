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
use Nowo\TaskBoardBundle\Repository\TaskRepositoryInterface;

use function count;

/**
 * Creates and updates tasks on a board.
 */
final readonly class TaskManager
{
    public function __construct(
        private TaskRepositoryInterface $taskRepository,
        private TaskChangeRecorder $changeRecorder,
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
    }

    public function updatePriority(Task $task, TaskPriority $priority, object $user): void
    {
        $oldPriority = $task->getPriority();
        $task->setPriority($priority);
        $this->changeRecorder->recordIfChanged($task, $user, TaskChangeType::Priority, $oldPriority, $priority);
        $this->taskRepository->save($task);
    }

    public function move(Task $task, ?string $columnId, int $position, object $user): void
    {
        $columns      = $this->orderedColumns($task->getBoard());
        $oldColumn    = $task->getColumn();
        $wasCompleted = $task->isCompleted();
        $column       = $this->resolveColumn($task->getBoard(), $columnId);

        $task
            ->setColumn($column)
            ->setPosition($position);

        $this->syncCompletionForColumn($task, $column, $columns);
        $this->changeRecorder->recordIfChanged($task, $user, TaskChangeType::Column, $oldColumn, $column);

        if ($wasCompleted !== $task->isCompleted()) {
            $this->changeRecorder->recordIfChanged($task, $user, TaskChangeType::Completed, $wasCompleted, $task->isCompleted());
        }

        $this->taskRepository->save($task);
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
        $this->move($task, $target->getId(), $this->nextPosition($task->getBoard(), $target), $user);

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

        $this->move($task, $target->getId(), $this->nextPosition($task->getBoard(), $target), $user);

        return true;
    }

    public function moveToDone(Task $task, object $user): bool
    {
        $columns = $this->orderedColumns($task->getBoard());
        if ($columns === []) {
            return false;
        }

        $done = $columns[array_key_last($columns)];
        $this->move($task, $done->getId(), $this->nextPosition($task->getBoard(), $done), $user);

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

        $this->move($task, $columnId, $this->nextPosition($task->getBoard(), $column), $user);

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
