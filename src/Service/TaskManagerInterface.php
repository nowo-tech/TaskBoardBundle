<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Service;

use DateTimeImmutable;
use InvalidArgumentException;
use Nowo\TaskBoardBundle\Dto\TaskColumnNavigation;
use Nowo\TaskBoardBundle\Dto\TaskFormData;
use Nowo\TaskBoardBundle\Entity\Task;
use Nowo\TaskBoardBundle\Entity\TaskBoard;
use Nowo\TaskBoardBundle\Enum\TaskPriority;
use Nowo\TaskBoardBundle\Exception\ColumnNotFoundException;
use Nowo\TaskBoardBundle\Exception\InvalidTaskScheduleException;
use Nowo\TaskBoardBundle\Exception\TaskReopenRefusedException;
use Nowo\TaskBoardBundle\Sync\Hook\LimitRefusal;
use Nowo\TaskBoardBundle\Validation\TaskConstraints;

/**
 * Since 2.10.0 (DDD audit G4): the port of {@see TaskManager}, which creates, updates, moves, archives and restores
 * tasks. A host's application layer depends on it and can double or decorate it; the container aliases it to the
 * bundle's class. The bundle's own controllers keep using the class, so a host decorator wraps the host's calls only.
 */
interface TaskManagerInterface
{
    /**
     * @param string|null $context since 2.3.0 (spec 215): why the task is created, kept on its "created" history row (e.g.
     *                             "recurring"), for a host's history label
     */
    public function create(TaskBoard $board, TaskFormData $data, object $creator, ?Task $parent = null, ?string $context = null): Task;

    /**
     * Since 2.2.1: a column change here is a move, as {@see moveToColumn()}: the host's limit is asked before anything changes,
     * the task goes to the end of its new column, the completion follows the column and TASK_COMPLETED / TASK_REOPENED fire
     * once. A caller that already moved the task passes its current column, so nothing moves twice.
     *
     * @throws TaskReopenRefusedException when the column change would reopen a completed task over the host's limit: nothing changes
     * @throws ColumnNotFoundException since 2.2.1, when `columnId` is not a column of the task's board: nothing changes
     */
    public function update(Task $task, TaskFormData $data, object $user): void;

    /**
     * Since 2.1.0 (spec 214, FR-026): changes only the dates, with the history and the event of the task form. The dates are
     * days: a task imported with a time keeps its time of day, and a date that did not change stays exactly as stored.
     *
     * @throws InvalidTaskScheduleException when the start is after the due date
     */
    public function reschedule(Task $task, ?DateTimeImmutable $startAt, ?DateTimeImmutable $dueAt, object $user): void;

    public function updatePriority(Task $task, TaskPriority $priority, object $user): void;

    /**
     * Since 2.3.0 (spec 216, FR-013): adds one tag, with the history and the TASK_UPDATED of the task form (its old tags in
     * the event). False when the tag is empty or already there.
     *
     * @throws InvalidArgumentException since audit IC-L3, the task form's rules: a tag over {@see TaskConstraints::TAG_MAX}
     *                                  characters, or a task that already has {@see TaskConstraints::TAGS_MAX} tags
     */
    public function addTag(Task $task, string $tag, object $user): bool;

    /**
     * Moves a task to a column at the given index and renumbers the target column (0..n-1) so
     * client-computed positions (e.g. drag & drop card counts) never collide with existing ones.
     */
    public function move(Task $task, ?string $columnId, int $position, object $user): void;

    public function getColumnNavigation(Task $task): TaskColumnNavigation;

    public function moveToPreviousColumn(Task $task, object $user): bool;

    public function moveToNextColumn(Task $task, object $user): bool;

    public function moveToDone(Task $task, object $user): bool;

    public function moveToColumn(Task $task, string $columnId, object $user): bool;

    /**
     * Since 2.2.0 (spec 213): makes an archived task active again, asking the host's limit when it adds an active task (a
     * completed one adds nothing). False when the task is not archived.
     *
     * @throws TaskReopenRefusedException when the host's limit refuses it: nothing changes
     */
    public function restore(Task $task, object $user): bool;

    /**
     * Since 2.2.0 (spec 213, audit IA-L6): the host's answer on `$newTasks` more active tasks on the board, asked before a write
     * that saves in several steps, so that no step is saved before a later one is refused. Null: allowed, or no limit.
     */
    public function activeTaskRefusal(TaskBoard $board, int $newTasks): ?LimitRefusal;

    /**
     * Since 2.2.0 (spec 213): archives a task by hand, with its history. False when it already is.
     */
    public function archive(Task $task, object $user): bool;

    /**
     * Since 1.23: records and saves a restored task; since 2.10.0 (spec 216) then dispatches TASK_RESTORED.
     */
    public function recordRestored(Task $task, object $user): void;
}
