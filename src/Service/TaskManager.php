<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Service;

use DateTimeImmutable;
use InvalidArgumentException;
use Nowo\TaskBoardBundle\Dto\TaskColumnNavigation;
use Nowo\TaskBoardBundle\Dto\TaskFormData;
use Nowo\TaskBoardBundle\Entity\BoardColumn;
use Nowo\TaskBoardBundle\Entity\Task;
use Nowo\TaskBoardBundle\Entity\TaskBoard;
use Nowo\TaskBoardBundle\Enum\TaskChangeType;
use Nowo\TaskBoardBundle\Enum\TaskPriority;
use Nowo\TaskBoardBundle\Event\AbstractTaskEvent;
use Nowo\TaskBoardBundle\Event\TaskArchivedEvent;
use Nowo\TaskBoardBundle\Event\TaskBoardEvents;
use Nowo\TaskBoardBundle\Event\TaskCompletedEvent;
use Nowo\TaskBoardBundle\Event\TaskCreatedEvent;
use Nowo\TaskBoardBundle\Event\TaskMovedEvent;
use Nowo\TaskBoardBundle\Event\TaskReopenedEvent;
use Nowo\TaskBoardBundle\Event\TaskRestoredEvent;
use Nowo\TaskBoardBundle\Event\TaskUpdatedEvent;
use Nowo\TaskBoardBundle\Exception\ColumnNotFoundException;
use Nowo\TaskBoardBundle\Exception\InvalidTaskScheduleException;
use Nowo\TaskBoardBundle\Exception\TaskReopenRefusedException;
use Nowo\TaskBoardBundle\Repository\TaskRepositoryInterface;
use Nowo\TaskBoardBundle\Support\ColumnTypes;
use Nowo\TaskBoardBundle\Sync\Hook\LimitRefusal;
use Nowo\TaskBoardBundle\Sync\Hook\SyncLimitCheckerInterface;
use Nowo\TaskBoardBundle\Validation\TaskConstraints;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

use function array_filter;
use function array_flip;
use function array_intersect_key;
use function array_keys;
use function count;
use function in_array;
use function mb_strlen;
use function sprintf;
use function trim;

use const DATE_ATOM;

/**
 * Creates and updates tasks on a board; after each saved write it dispatches the task events (since 1.10:
 * {@see TaskBoardEvents::TASK_CREATED}, TASK_UPDATED, TASK_MOVED, TASK_COMPLETED, TASK_REOPENED; since 2.10.0 TASK_ARCHIVED and
 * TASK_RESTORED).
 */
final readonly class TaskManager implements TaskManagerInterface
{
    /**
     * @param EventDispatcherInterface|null $eventDispatcher Dispatches the task events (none: no events)
     */
    public function __construct(
        private TaskRepositoryInterface $taskRepository,
        private TaskChangeRecorder $changeRecorder,
        private ?EventDispatcherInterface $eventDispatcher = null,
        // Since 1.29.0 (audit HN-M1): the host's limit, asked when a move reopens a completed task.
        private ?SyncLimitCheckerInterface $limits = null,
    ) {
    }

    /**
     * @param string|null $context since 2.3.0 (spec 215): why the task is created, kept on its "created" history row (e.g.
     *                             "recurring"), for a host's history label
     */
    public function create(TaskBoard $board, TaskFormData $data, object $creator, ?Task $parent = null, ?string $context = null): Task
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
        $task->setColor($data->color);
        $task->setStartAt($data->startAt);

        $this->changeRecorder->recordCreated($task, $creator, $context);
        $this->taskRepository->save($task);
        $this->dispatch(new TaskCreatedEvent($task, $creator), TaskBoardEvents::TASK_CREATED);

        return $task;
    }

    /**
     * Since 2.2.1: a column change here is a move, as {@see moveToColumn()}: the host's limit is asked before anything changes,
     * the task goes to the end of its new column, the completion follows the column and TASK_COMPLETED / TASK_REOPENED fire
     * once. A caller that already moved the task passes its current column, so nothing moves twice.
     *
     * @throws TaskReopenRefusedException when the column change would reopen a completed task over the host's limit: nothing changes
     * @throws ColumnNotFoundException since 2.2.1, when `columnId` is not a column of the task's board: nothing changes
     */
    public function update(Task $task, TaskFormData $data, object $user): void
    {
        $oldTitle            = $task->getTitle();
        $oldDescription      = $task->getDescription();
        $oldPriority         = $task->getPriority();
        $oldColumn           = $task->getColumn();
        $oldEstimatedMinutes = $task->getEstimatedMinutes();
        $oldDueAt            = $task->getDueAt();
        $oldTags             = $task->getTags();
        $oldColor            = $task->getColor();
        $oldStartAt          = $task->getStartAt();
        $wasCompleted        = $task->isCompleted();

        // Since 2.2.1 (audit IB-L1): data from fromTask() without a column keeps the task's own, even none: never a move.
        $newColumn = $data->keepsColumn && ($data->columnId === null || $data->columnId === '')
            ? $oldColumn
            : $this->resolveColumn($task->getBoard(), $data->columnId);
        // Since 2.2.1: a column that is not the board's is refused, as by move(), instead of leaving the task without one.
        if ($data->columnId !== null && $data->columnId !== '' && !$newColumn instanceof BoardColumn) {
            throw ColumnNotFoundException::forBoard($data->columnId, $task->getBoard()->getId());
        }
        $moves   = $oldColumn?->getId() !== $newColumn?->getId();
        $columns = $this->orderedColumns($task->getBoard());
        if ($moves) {
            $this->assertReopenAllowed($task, $newColumn, $columns);
            if ($newColumn instanceof BoardColumn) {
                $task->setPosition($this->nextPosition($task->getBoard(), $newColumn));
            }
        }

        $task
            ->setTitle($data->title)
            ->setDescription($data->description)
            ->setPriority($data->priority)
            ->setColumn($newColumn)
            ->setEstimatedMinutes($data->estimatedMinutes)
            ->setDueAt($data->dueAt)
            ->setTags($data->tags)
            ->setColor($data->color)
            ->setStartAt($data->startAt);
        if ($moves) {
            $this->syncCompletionForColumn($task, $newColumn, $columns);
        }

        $this->changeRecorder->recordIfChanged($task, $user, TaskChangeType::Title, $oldTitle, $data->title);
        $this->changeRecorder->recordIfChanged($task, $user, TaskChangeType::Description, $oldDescription, $data->description);
        $this->changeRecorder->recordIfChanged($task, $user, TaskChangeType::Priority, $oldPriority, $data->priority);
        $this->changeRecorder->recordIfChanged($task, $user, TaskChangeType::Column, $oldColumn, $newColumn);
        $this->changeRecorder->recordIfChanged($task, $user, TaskChangeType::EstimatedMinutes, $oldEstimatedMinutes, $data->estimatedMinutes);
        $this->changeRecorder->recordIfChanged($task, $user, TaskChangeType::StartDate, $oldStartAt, $data->startAt);
        $this->changeRecorder->recordIfChanged($task, $user, TaskChangeType::DueDate, $oldDueAt, $data->dueAt);
        $this->changeRecorder->recordIfChanged($task, $user, TaskChangeType::Tags, $oldTags, $task->getTags());
        $this->changeRecorder->recordIfChanged($task, $user, TaskChangeType::Color, $oldColor, $task->getColor());
        if ($wasCompleted !== $task->isCompleted()) {
            $this->changeRecorder->recordIfChanged($task, $user, TaskChangeType::Completed, $wasCompleted, $task->isCompleted());
        }

        $this->taskRepository->save($task);

        $changed = array_keys(array_filter([
            'title'            => $oldTitle !== $task->getTitle(),
            'description'      => $oldDescription !== $task->getDescription(),
            'priority'         => $oldPriority !== $task->getPriority(),
            'column'           => $oldColumn?->getId() !== $newColumn?->getId(),
            'estimatedMinutes' => $oldEstimatedMinutes !== $task->getEstimatedMinutes(),
            'startAt'          => $oldStartAt?->format(DATE_ATOM) !== $task->getStartAt()?->format(DATE_ATOM),
            'dueAt'            => $oldDueAt?->format(DATE_ATOM) !== $task->getDueAt()?->format(DATE_ATOM),
            'tags'             => $oldTags !== $task->getTags(),
            'color'            => $oldColor !== $task->getColor(),
        ]));
        if ($changed !== []) {
            $previous = ['title' => $oldTitle, 'description' => $oldDescription, 'priority' => $oldPriority, 'column' => $oldColumn, 'estimatedMinutes' => $oldEstimatedMinutes, 'startAt' => $oldStartAt, 'dueAt' => $oldDueAt, 'tags' => $oldTags, 'color' => $oldColor];
            $this->dispatch(new TaskUpdatedEvent($task, $user, $changed, array_intersect_key($previous, array_flip($changed))), TaskBoardEvents::TASK_UPDATED);
        }
        if ($moves) {
            $this->dispatch(new TaskMovedEvent($task, $user, $oldColumn, $newColumn), TaskBoardEvents::TASK_MOVED);
        }
        $this->dispatchCompletion($task, $user, $wasCompleted);
    }

    /**
     * Since 2.1.0 (spec 214, FR-026): changes only the dates, with the history and the event of the task form. The dates are
     * days: a task imported with a time keeps its time of day, and a date that did not change stays exactly as stored.
     *
     * @throws InvalidTaskScheduleException when the start is after the due date
     */
    public function reschedule(Task $task, ?DateTimeImmutable $startAt, ?DateTimeImmutable $dueAt, object $user): void
    {
        if ($startAt instanceof DateTimeImmutable && $dueAt instanceof DateTimeImmutable && $startAt->format('Y-m-d') > $dueAt->format('Y-m-d')) {
            throw new InvalidTaskScheduleException('The start date is after the due date.');
        }

        $oldStartAt = $task->getStartAt();
        $oldDueAt   = $task->getDueAt();
        $newStartAt = $this->onDay($oldStartAt, $startAt);
        $newDueAt   = $this->onDay($oldDueAt, $dueAt);

        $changed = array_keys(array_filter([
            'startAt' => $oldStartAt?->format(DATE_ATOM) !== $newStartAt?->format(DATE_ATOM),
            'dueAt'   => $oldDueAt?->format(DATE_ATOM) !== $newDueAt?->format(DATE_ATOM),
        ]));
        if ($changed === []) {
            return;
        }

        $task->setStartAt($newStartAt)->setDueAt($newDueAt);
        $this->changeRecorder->recordIfChanged($task, $user, TaskChangeType::StartDate, $oldStartAt, $newStartAt);
        $this->changeRecorder->recordIfChanged($task, $user, TaskChangeType::DueDate, $oldDueAt, $newDueAt);
        $this->taskRepository->save($task);
        $this->dispatch(new TaskUpdatedEvent($task, $user, $changed, array_intersect_key(['startAt' => $oldStartAt, 'dueAt' => $oldDueAt], array_flip($changed))), TaskBoardEvents::TASK_UPDATED);
    }

    /** `$day` at the time of day of `$current`; midnight when there was no date. */
    private function onDay(?DateTimeImmutable $current, ?DateTimeImmutable $day): ?DateTimeImmutable
    {
        if (!$day instanceof DateTimeImmutable) {
            return null;
        }
        if (!$current instanceof DateTimeImmutable) {
            return $day->setTime(0, 0);
        }

        return $current->setDate((int) $day->format('Y'), (int) $day->format('n'), (int) $day->format('j'));
    }

    public function updatePriority(Task $task, TaskPriority $priority, object $user): void
    {
        $oldPriority = $task->getPriority();
        $task->setPriority($priority);
        $this->changeRecorder->recordIfChanged($task, $user, TaskChangeType::Priority, $oldPriority, $priority);
        $this->taskRepository->save($task);
        if ($oldPriority !== $priority) {
            $this->dispatch(new TaskUpdatedEvent($task, $user, ['priority'], ['priority' => $oldPriority]), TaskBoardEvents::TASK_UPDATED);
        }
    }

    /**
     * Since 2.3.0 (spec 216, FR-013): adds one tag, with the history and the TASK_UPDATED of the task form (its old tags in
     * the event). False when the tag is empty or already there.
     *
     * @throws InvalidArgumentException since audit IC-L3, the task form's rules: a tag over {@see TaskConstraints::TAG_MAX}
     *                                  characters, or a task that already has {@see TaskConstraints::TAGS_MAX} tags
     */
    public function addTag(Task $task, string $tag, object $user): bool
    {
        $tag     = trim($tag);
        $oldTags = $task->getTags();
        if ($tag === '' || in_array($tag, $oldTags, true)) {
            return false;
        }
        if (mb_strlen($tag) > TaskConstraints::TAG_MAX) {
            throw new InvalidArgumentException(sprintf('A tag has at most %d characters.', TaskConstraints::TAG_MAX));
        }
        if (count($oldTags) >= TaskConstraints::TAGS_MAX) {
            throw new InvalidArgumentException(sprintf('A task has at most %d tags.', TaskConstraints::TAGS_MAX));
        }
        $task->setTags([...$oldTags, $tag]);
        $this->changeRecorder->recordIfChanged($task, $user, TaskChangeType::Tags, $oldTags, $task->getTags());
        $this->taskRepository->save($task);
        $this->dispatch(new TaskUpdatedEvent($task, $user, ['tags'], ['tags' => $oldTags]), TaskBoardEvents::TASK_UPDATED);

        return true;
    }

    /**
     * @throws ColumnNotFoundException when $columnId is not a column of the task's board
     * @throws TaskReopenRefusedException since 1.29.0, when the move would reopen a completed task over the host's limit
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
        $this->assertReopenAllowed($task, $column, $columns);

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
        $this->dispatchCompletion($task, $user, $wasCompleted);
    }

    /**
     * Since 1.29.0 (audit HN-M1): a completed task outside the last column is active again, so the host's limit is asked.
     *
     * @param list<BoardColumn> $columns
     *
     * @throws TaskReopenRefusedException
     */
    private function assertReopenAllowed(Task $task, ?BoardColumn $column, array $columns): void
    {
        // Since 2.17.0 (FR-033): any column that is not done (column type), not only "not the last".
        if ($task->isCompleted() && !$task->isArchived() && $column instanceof BoardColumn && $columns !== [] && !ColumnTypes::isDone($column, $columns)) {
            $refusal = $this->limits?->check($task->getBoard(), 1, 0);
            if ($refusal instanceof LimitRefusal) {
                throw new TaskReopenRefusedException($refusal);
            }
        }
    }

    private function dispatchCompletion(Task $task, object $user, bool $wasCompleted): void
    {
        if (!$wasCompleted && $task->isCompleted()) {
            $this->dispatch(new TaskCompletedEvent($task, $user), TaskBoardEvents::TASK_COMPLETED);
        } elseif ($wasCompleted && !$task->isCompleted()) {
            $this->dispatch(new TaskReopenedEvent($task, $user), TaskBoardEvents::TASK_REOPENED);
        }
    }

    /**
     * Dispatches a task event (after the write was saved) when a dispatcher is wired.
     */
    private function dispatch(AbstractTaskEvent|TaskArchivedEvent|TaskRestoredEvent $event, string $eventName): void
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
        // Since 2.17.0 (FR-033): the first done or closed column (the last one on an untyped board).
        $done      = ColumnTypes::doneColumn($task->getBoard(), $columns);
        $isDoneNow = $current instanceof BoardColumn && ColumnTypes::isDone($current, $columns);

        return new TaskColumnNavigation(
            currentColumnName: $current?->getName(),
            previousColumnName: $previous?->getName(),
            nextColumnName: $next?->getName(),
            doneColumnName: $done?->getName(),
            canPrevious: $index > 0,
            canNext: $next !== null && ($index < 0 || $index < $last),
            canDone: $done !== null && (!$isDoneNow || !$task->isCompleted()),
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

        // Since 2.17.0 (FR-033): the first done or closed column (the last one on an untyped board).
        $done = ColumnTypes::doneColumn($task->getBoard(), $columns) ?? $columns[array_key_last($columns)];
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

        // Since 2.17.0 (FR-033): a done or closed column completes the task (the last one on an untyped board).
        if (ColumnTypes::isDone($column, $columns)) {
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

    /**
     * Since 2.2.0 (spec 213): makes an archived task active again, asking the host's limit when it adds an active task (a
     * completed one adds nothing). False when the task is not archived.
     *
     * @throws TaskReopenRefusedException when the host's limit refuses it: nothing changes
     */
    public function restore(Task $task, object $user): bool
    {
        if (!$task->isArchived()) {
            return false;
        }
        $refusal = $this->limits?->check($task->getBoard(), $task->isCompleted() ? 0 : 1, 0);
        if ($refusal instanceof LimitRefusal) {
            throw new TaskReopenRefusedException($refusal);
        }

        $task->restore();
        $this->recordRestored($task, $user);

        return true;
    }

    /**
     * Since 2.2.0 (spec 213, audit IA-L6): the host's answer on `$newTasks` more active tasks on the board, asked before a write
     * that saves in several steps, so that no step is saved before a later one is refused. Null: allowed, or no limit.
     */
    public function activeTaskRefusal(TaskBoard $board, int $newTasks): ?LimitRefusal
    {
        return $newTasks > 0 ? $this->limits?->check($board, $newTasks, 0) : null;
    }

    /**
     * Since 2.2.0 (spec 213): archives a task by hand, with its history. False when it already is.
     */
    public function archive(Task $task, object $user): bool
    {
        if ($task->isArchived()) {
            return false;
        }

        $task->archive();
        $this->changeRecorder->record($task, $user, TaskChangeType::Archived, null, 'manual');
        $this->taskRepository->save($task);
        // Since 2.10.0 (spec 216): after the save, ids only.
        $this->dispatch(TaskArchivedEvent::of($task, $user, $this->changeRecorder->currentCause()), TaskBoardEvents::TASK_ARCHIVED);

        return true;
    }

    /**
     * Since 1.23: records and saves a restored task; since 2.10.0 (spec 216) then dispatches TASK_RESTORED.
     */
    public function recordRestored(Task $task, object $user): void
    {
        $this->changeRecorder->record($task, $user, TaskChangeType::Restored, null, 'manual');
        $this->taskRepository->save($task);
        $this->dispatch(TaskRestoredEvent::of($task, $user, $this->changeRecorder->currentCause()), TaskBoardEvents::TASK_RESTORED);
    }
}
