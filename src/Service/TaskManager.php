<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Service;

use Closure;
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
use Nowo\TaskBoardBundle\Event\TaskStatusChangedEvent;
use Nowo\TaskBoardBundle\Event\TaskUpdatedEvent;
use Nowo\TaskBoardBundle\Exception\ColumnNotFoundException;
use Nowo\TaskBoardBundle\Exception\ColumnTransitionRefusedException;
use Nowo\TaskBoardBundle\Exception\InvalidTaskScheduleException;
use Nowo\TaskBoardBundle\Exception\TaskReopenRefusedException;
use Nowo\TaskBoardBundle\Port\Snapshots;
use Nowo\TaskBoardBundle\Repository\TaskRepositoryInterface;
use Nowo\TaskBoardBundle\Support\ActorId;
use Nowo\TaskBoardBundle\Support\ColumnTypes;
use Nowo\TaskBoardBundle\Sync\Hook\LimitRefusal;
use Nowo\TaskBoardBundle\Sync\Hook\SyncLimitCheckerInterface;
use Nowo\TaskBoardBundle\Transition\ColumnTransitionPolicyInterface;
use Nowo\TaskBoardBundle\Tree\TaskTreeLimits;
use Nowo\TaskBoardBundle\Tree\TaskTreeRefusedException;
use Nowo\TaskBoardBundle\Tree\TaskTreeStoreInterface;
use Nowo\TaskBoardBundle\Validation\TaskConstraints;
use Nowo\TaskBoardBundle\ValueObject\ColumnTransition;
use Nowo\TaskBoardBundle\ValueObject\ColumnTransitionVia;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

use function array_filter;
use function array_flip;
use function array_intersect_key;
use function array_keys;
use function array_map;
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
        // Since 2.16.0 (spec 234, FR-014): asked before a task enters a column; none: every move is allowed.
        private ?ColumnTransitionPolicyInterface $transitions = null,
        // Since 2.20.0 (Beacon spec 234, T004): `max_subtask_depth`, checked on the parent's rows locked in the store.
        private ?TaskTreeStoreInterface $tree = null,
        private TaskTreeLimits $treeLimits = new TaskTreeLimits(),
        // Since 2.21.0 (Beacon spec 239, FR-013): the fixed lock order of a move's rows; none: written as before.
        private ?OrderedRowLockInterface $rowLock = null,
    ) {
    }

    /**
     * @param string|null $context since 2.3.0 (spec 215): why the task is created, kept on its "created" history row (e.g.
     *                             "recurring"), for a host's history label
     * @param ColumnTransitionVia|null $via since 2.16.0 (spec 234): how the task enters its first column, for the column
     *                                      transition policy; null: a person in the board UI (`web`, `create`)
     *
     * @throws ColumnTransitionRefusedException since 2.16.0, when the policy refuses the column: nothing is created
     * @throws TaskTreeRefusedException since 2.20.0, `too_deep` when the new subtask would be deeper than
     *                                  `max_subtask_depth` (`conflict` when a concurrent tree change held the parent's rows):
     *                                  nothing is created
     */
    public function create(TaskBoard $board, TaskFormData $data, object $creator, ?Task $parent = null, ?string $context = null, ?ColumnTransitionVia $via = null): Task
    {
        if ($parent instanceof Task && $this->treeLimits->maxSubtaskDepth !== null) {
            if (!$this->tree instanceof TaskTreeStoreInterface) {
                // No store (a hand-built manager): the loaded tree decides.
                if (!$this->treeLimits->allowsChildOf($parent)) {
                    throw TaskTreeRefusedException::tooDeep();
                }

                return $this->createTask($board, $data, $creator, $parent, $context, $via);
            }

            // The parent's path locked, as a parent change locks it: a concurrent move cannot take it deeper meanwhile.
            // @igor-ignore - The closure runs once, now, inside transactional(); the stateless store keeps no reference to it.
            return $this->tree->transactional(function () use ($board, $data, $creator, $parent, $context, $via): Task {
                $this->assertSubtaskDepth($this->lockedDepth($parent->getId()) + 1);

                return $this->createTask($board, $data, $creator, $parent, $context, $via);
            });
        }

        return $this->createTask($board, $data, $creator, $parent, $context, $via);
    }

    /**
     * Since 2.20.0: the depth of a task as committed (its ancestors, each locked), stopping one level past the limit.
     */
    private function lockedDepth(string $taskId): int
    {
        $limit = $this->treeLimits->maxDepth();
        $depth = 0;
        for ($node = $this->tree?->lockedNode($taskId); $node?->parentId !== null && $depth <= $limit; $node = $this->tree?->lockedNode($node->parentId)) {
            ++$depth;
        }

        return $depth;
    }

    /**
     * @throws TaskTreeRefusedException
     */
    private function assertSubtaskDepth(int $depth): void
    {
        if (!$this->treeLimits->allows($depth)) {
            throw TaskTreeRefusedException::tooDeep();
        }
    }

    private function createTask(TaskBoard $board, TaskFormData $data, object $creator, ?Task $parent, ?string $context, ?ColumnTransitionVia $via): Task
    {
        $column = $this->resolveColumn($board, $data->columnId);
        if ($column instanceof BoardColumn) {
            $this->assertTransition($board, null, $column, $creator, $via ?? ColumnTransitionVia::web(ColumnTransitionVia::OP_CREATE), $data->tags);
        }

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
     * @param ColumnTransitionVia|null $via since 2.16.0: how a column change happens; null: a person in the board UI (`web`)
     *
     * @throws TaskReopenRefusedException when the column change would reopen a completed task over the host's limit: nothing changes
     * @throws ColumnNotFoundException since 2.2.1, when `columnId` is not a column of the task's board: nothing changes
     * @throws ColumnTransitionRefusedException since 2.16.0, when the column transition policy refuses the column change, or
     *                                          (audit PC-L2) the new tags of a task that stays in its column (operation
     *                                          `retag`): nothing changes
     */
    public function update(Task $task, TaskFormData $data, object $user, ?ColumnTransitionVia $via = null): void
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
        // Since 2.16.0 (audit PC-L2): new tags on a task that stays in its column are asked too (operation `retag`).
        if (!$moves && $newColumn instanceof BoardColumn && $data->tags !== $oldTags) {
            $this->assertTransition($task->getBoard(), $task, $newColumn, $user, self::retag($via), $data->tags);
        }
        if ($moves) {
            if ($newColumn instanceof BoardColumn) {
                $this->assertTransition($task->getBoard(), $task, $newColumn, $user, $via ?? ColumnTransitionVia::web(), $data->tags);
            }
            $this->assertReopenAllowed($task, $newColumn, $columns);
        }
        $nextPosition = $moves && $newColumn instanceof BoardColumn ? $this->nextPosition($task->getBoard(), $newColumn) : null;

        // @igor-ignore - The closure runs once, now (inside transactional() or directly), and is never stored.
        $write = function () use ($task, $data, $user, $moves, $newColumn, $columns, $nextPosition, $oldTitle, $oldDescription, $oldPriority, $oldColumn, $oldEstimatedMinutes, $oldStartAt, $oldDueAt, $oldTags, $oldColor, $wasCompleted): void {
            if ($nextPosition !== null) {
                $task->setPosition($nextPosition);
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
        };
        // Since 2.21.0 (Beacon spec 239, FR-013): a column change locks the column, then the task, as a move does.
        if ($moves) {
            $this->lockedWrite($newColumn, [$task], $write);
        } else {
            $write();
        }

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
            $this->dispatchStatusChanged($task, $oldColumn, $newColumn, $user, $via ?? ColumnTransitionVia::web());
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
     * @param ColumnTransitionVia|null $via since 2.16.0: who tags it, for the column transition policy (operation `retag`);
     *                                      null: a person in the board UI (`web`)
     *
     * @throws InvalidArgumentException since audit IC-L3, the task form's rules: a tag over {@see TaskConstraints::TAG_MAX}
     *                                  characters, or a task that already has {@see TaskConstraints::TAGS_MAX} tags
     * @throws ColumnTransitionRefusedException since 2.16.0 (audit PC-L2), when the policy refuses the new tags: nothing changes
     */
    public function addTag(Task $task, string $tag, object $user, ?ColumnTransitionVia $via = null): bool
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
        $column = $task->getColumn();
        if ($column instanceof BoardColumn) {
            // Since 2.16.0 (audit PC-L2): the column transition policy is asked about the new tags (operation `retag`).
            $this->assertTransition($task->getBoard(), $task, $column, $user, self::retag($via), [...$oldTags, $tag]);
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
    public function move(Task $task, ?string $columnId, int $position, object $user, ?ColumnTransitionVia $via = null): void
    {
        $this->applyMove($task, $columnId, $position, $user, true, $via);
    }

    private function applyMove(Task $task, ?string $columnId, int $position, object $user, bool $renumberSiblings, ?ColumnTransitionVia $via): void
    {
        $columns      = $this->orderedColumns($task->getBoard());
        $oldColumn    = $task->getColumn();
        $wasCompleted = $task->isCompleted();
        $column       = $this->resolveColumn($task->getBoard(), $columnId);

        if ($columnId !== null && $columnId !== '' && !$column instanceof BoardColumn) {
            throw ColumnNotFoundException::forBoard($columnId, $task->getBoard()->getId());
        }
        if ($column instanceof BoardColumn && $oldColumn?->getId() !== $column->getId()) {
            $this->assertTransition($task->getBoard(), $task, $column, $user, $via ?? ColumnTransitionVia::web());
        }
        $this->assertReopenAllowed($task, $column, $columns);

        $siblings = $renumberSiblings ? $this->columnSiblings($task, $column) : [];
        // @igor-ignore - The closure runs once, now (inside transactional() or directly), and is never stored.
        $write = function () use ($task, $column, $position, $renumberSiblings, $siblings, $columns, $user, $oldColumn, $wasCompleted): void {
            // Since 2.21.0 (Beacon spec 239, FR-013): with the rows locked, the column as committed now. A move that waited
            // for a concurrent one renumbers from that one's positions, never from what it read before the lock.
            if ($renumberSiblings && $this->rowLock instanceof OrderedRowLockInterface) {
                $siblings = $this->lockedSiblings($this->rowLock, $task, $column);
            }
            $task
                ->setColumn($column)
                ->setPosition($position);

            if ($renumberSiblings) {
                $this->renumberColumn($task, $siblings, $position);
            }

            $this->syncCompletionForColumn($task, $column, $columns);
            $this->changeRecorder->recordIfChanged($task, $user, TaskChangeType::Column, $oldColumn, $column);

            if ($wasCompleted !== $task->isCompleted()) {
                $this->changeRecorder->recordIfChanged($task, $user, TaskChangeType::Completed, $wasCompleted, $task->isCompleted());
            }

            $this->taskRepository->save($task);
        };
        // A renumbering move always locks its column (the column's tasks change together); a plain move only the one it enters.
        $this->lockedWrite($renumberSiblings || $oldColumn?->getId() !== $column?->getId() ? $column : null, [$task, ...$siblings], $write);

        if ($oldColumn?->getId() !== $column?->getId()) {
            $this->dispatch(new TaskMovedEvent($task, $user, $oldColumn, $column), TaskBoardEvents::TASK_MOVED);
            $this->dispatchStatusChanged($task, $oldColumn, $column, $user, $via ?? ColumnTransitionVia::web());
        }
        $this->dispatchCompletion($task, $user, $wasCompleted);
    }

    /**
     * Since 2.21.0 (Beacon spec 239, FR-013): runs `$write` in one transaction after locking every row it writes in the
     * fixed order (the column a task enters, then the tasks, each by id); the caller dispatches the events after it.
     * Without a lock service (a hand-built manager) `$write` runs as before.
     *
     * @param list<Task> $tasks
     * @param Closure(): void $write
     */
    private function lockedWrite(?BoardColumn $entered, array $tasks, Closure $write): void
    {
        if (!$this->rowLock instanceof OrderedRowLockInterface) {
            $write();

            return;
        }
        $rowLock = $this->rowLock;
        // @igor-ignore - The closure runs once, now, inside transactional(); the stateless lock keeps no reference to it.
        $rowLock->transactional(static function () use ($rowLock, $entered, $tasks, $write): void {
            // A task entering a column checks the column's row (its foreign key): the column first, as everywhere.
            if ($entered instanceof BoardColumn) {
                $rowLock->lockColumns([$entered->getId()]);
            }
            $rowLock->lockTasks(array_map(static fn (Task $task): string => $task->getId(), $tasks));
            $write();
        });
    }

    /**
     * Since 2.16.0 (spec 234): asks the column transition policy whether the task may enter `$to`, without changing
     * anything, for a caller that checks every item of a write before the first save (the Task API's bulk update). Staying
     * in its column is always allowed. `$tags`: the tags the write gives the task (null: its current ones).
     *
     * @param list<string>|null $tags
     *
     * @throws ColumnTransitionRefusedException
     */
    public function assertCanEnter(Task $task, BoardColumn $to, object $actor, ColumnTransitionVia $via, ?array $tags = null): void
    {
        if ($task->getColumn()?->getId() !== $to->getId()) {
            $this->assertTransition($task->getBoard(), $task, $to, $actor, $via, $tags);
        }
    }

    /**
     * Since 2.16.0 (spec 234, FR-014): asks the column transition policy before anything changes.
     *
     * @param list<string>|null $tags the tags the task will have (null: its current ones)
     *
     * @throws ColumnTransitionRefusedException
     */
    private function assertTransition(TaskBoard $board, ?Task $task, BoardColumn $to, object $actor, ColumnTransitionVia $via, ?array $tags = null): void
    {
        if (!$this->transitions instanceof ColumnTransitionPolicyInterface) {
            return;
        }
        $columns    = $this->orderedColumns($board);
        $last       = $columns === [] ? null : $columns[array_key_last($columns)];
        $from       = $task?->getColumn();
        $transition = new ColumnTransition(
            Snapshots::board($board),
            $task instanceof Task ? Snapshots::task($task) : null,
            $from instanceof BoardColumn ? Snapshots::column($from, $from->getId() === $last?->getId(), $columns) : null,
            Snapshots::column($to, $to->getId() === $last?->getId(), $columns),
            $actor,
            ActorId::of($actor),
            $via,
            array_values($tags ?? $task?->getTags() ?? []),
        );
        $decision = $this->transitions->decide($transition);
        if (!$decision->allowed) {
            throw new ColumnTransitionRefusedException($transition, $decision);
        }
    }

    /**
     * Since 2.16.0 (audit PC-L2): the caller's channel, as operation `retag` (`web` without a caller's).
     */
    private static function retag(?ColumnTransitionVia $via): ColumnTransitionVia
    {
        return new ColumnTransitionVia($via->channel ?? ColumnTransitionVia::WEB, ColumnTransitionVia::OP_RETAG);
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
        // Since 2.19.0 (FR-033): any column that is not done (column type), not only "not the last".
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
     * Since 2.16.0 (spec 234): TASK_STATUS_CHANGED, snapshots only; since 2.19.0 with the new column's type.
     */
    private function dispatchStatusChanged(Task $task, ?BoardColumn $from, ?BoardColumn $to, object $user, ColumnTransitionVia $via): void
    {
        if (!$to instanceof BoardColumn || !$this->eventDispatcher instanceof EventDispatcherInterface) {
            return;
        }
        $columns = $this->orderedColumns($task->getBoard());
        $last    = $columns === [] ? null : $columns[array_key_last($columns)];
        $target  = Snapshots::column($to, $to->getId() === $last?->getId(), $columns);
        $this->dispatch(new TaskStatusChangedEvent(
            Snapshots::task($task),
            $from instanceof BoardColumn ? Snapshots::column($from, $from->getId() === $last?->getId(), $columns) : null,
            $target,
            ActorId::of($user),
            $via,
            // Since 2.19.0 (FR-033): the new column's type.
            $target->type,
        ), TaskBoardEvents::TASK_STATUS_CHANGED);
    }

    /**
     * Dispatches a task event (after the write was saved) when a dispatcher is wired.
     */
    private function dispatch(AbstractTaskEvent|TaskArchivedEvent|TaskRestoredEvent|TaskStatusChangedEvent $event, string $eventName): void
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
        // Since 2.19.0 (FR-033): the first done or closed column (the last one on an untyped board).
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

    /**
     * @param ColumnTransitionVia|null $via since 2.16.0: null is a person in the board UI (`web`)
     *
     * @throws ColumnTransitionRefusedException since 2.16.0
     * @throws TaskReopenRefusedException since 1.29.0, when the move would reopen a completed task over the host's limit
     */
    public function moveToPreviousColumn(Task $task, object $user, ?ColumnTransitionVia $via = null): bool
    {
        $columns = $this->orderedColumns($task->getBoard());
        $index   = $this->currentColumnIndex($task, $columns);

        if ($index <= 0) {
            return false;
        }

        $target = $columns[$index - 1];
        $this->applyMove($task, $target->getId(), $this->nextPosition($task->getBoard(), $target), $user, false, $via);

        return true;
    }

    /**
     * @param ColumnTransitionVia|null $via since 2.16.0: null is a person in the board UI (`web`)
     *
     * @throws ColumnTransitionRefusedException since 2.16.0
     * @throws TaskReopenRefusedException since 1.29.0, when the move would reopen a completed task over the host's limit
     */
    public function moveToNextColumn(Task $task, object $user, ?ColumnTransitionVia $via = null): bool
    {
        $columns = $this->orderedColumns($task->getBoard());
        $index   = $this->currentColumnIndex($task, $columns);
        $target  = $index < 0 ? ($columns[0] ?? null) : ($columns[$index + 1] ?? null);

        if ($target === null) {
            return false;
        }

        $this->applyMove($task, $target->getId(), $this->nextPosition($task->getBoard(), $target), $user, false, $via);

        return true;
    }

    /**
     * @param ColumnTransitionVia|null $via since 2.16.0: null is a person in the board UI (`web`)
     *
     * @throws ColumnTransitionRefusedException since 2.16.0
     * @throws TaskReopenRefusedException since 1.29.0, when the move would reopen a completed task over the host's limit
     */
    public function moveToDone(Task $task, object $user, ?ColumnTransitionVia $via = null): bool
    {
        $columns = $this->orderedColumns($task->getBoard());
        if ($columns === []) {
            return false;
        }

        // Since 2.19.0 (FR-033): the first done or closed column (the last one on an untyped board).
        $done = ColumnTypes::doneColumn($task->getBoard(), $columns) ?? $columns[array_key_last($columns)];
        $this->applyMove($task, $done->getId(), $this->nextPosition($task->getBoard(), $done), $user, false, $via);

        return true;
    }

    /**
     * @param ColumnTransitionVia|null $via since 2.16.0 (spec 234): how the move happens, for the column transition policy;
     *                                      null: a person in the board UI (`web`). The Task API always passes its
     *                                      principal's channel.
     *
     * @throws ColumnTransitionRefusedException since 2.16.0, when the policy refuses: nothing changes
     * @throws TaskReopenRefusedException since 1.29.0, when the move would reopen a completed task over the host's limit
     */
    public function moveToColumn(Task $task, string $columnId, object $user, ?ColumnTransitionVia $via = null): bool
    {
        if ($columnId === '') {
            return false;
        }

        $column = $this->resolveColumn($task->getBoard(), $columnId);
        if (!$column instanceof BoardColumn || $task->getColumn()?->getId() === $columnId) {
            return false;
        }

        $this->applyMove($task, $columnId, $this->nextPosition($task->getBoard(), $column), $user, false, $via);

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

        // Since 2.19.0 (FR-033): a done or closed column completes the task (the last one on an untyped board).
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
     * The other tasks of `$column` (active and completed, not archived), by position.
     *
     * @return list<Task>
     */
    private function columnSiblings(Task $task, ?BoardColumn $column): array
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

        return $siblings;
    }

    /**
     * Since 2.21.0 (Beacon spec 239, FR-013): the other tasks of `$column` as committed now, each row locked, by position
     * (then id); the moved task is brought up to date too. Inside the locked write only.
     *
     * @return list<Task>
     */
    private function lockedSiblings(OrderedRowLockInterface $rowLock, Task $task, ?BoardColumn $column): array
    {
        $siblings = [];
        foreach ($rowLock->lockedColumnTasks($task->getBoard()->getId(), $column?->getId(), [$task->getId()]) as $candidate) {
            if ($candidate->getId() !== $task->getId() && $candidate->getColumn()?->getId() === $column?->getId() && !$candidate->isArchived()) {
                $siblings[] = $candidate;
            }
        }
        usort($siblings, static fn (Task $a, Task $b): int => [$a->getPosition(), $a->getId()] <=> [$b->getPosition(), $b->getId()]);

        return $siblings;
    }

    /**
     * Inserts $task at index $position among the other tasks of its column and rewrites positions as 0..n-1.
     * Siblings whose position changes are saved; the moved task is saved by the caller.
     *
     * @param list<Task> $siblings from {@see self::columnSiblings()}, by position
     */
    private function renumberColumn(Task $task, array $siblings, int $position): void
    {
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
