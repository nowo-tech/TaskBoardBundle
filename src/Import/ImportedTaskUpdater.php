<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Import;

use Closure;
use DateTimeImmutable;
use Nowo\TaskBoardBundle\Entity\BoardColumn;
use Nowo\TaskBoardBundle\Entity\Task;
use Nowo\TaskBoardBundle\Entity\TaskMember;
use Nowo\TaskBoardBundle\Enum\TaskChangeType;
use Nowo\TaskBoardBundle\Enum\TaskMemberRole;
use Nowo\TaskBoardBundle\Import\Dto\ImportedTaskDto;
use Nowo\TaskBoardBundle\Service\TaskChangeRecorder;
use Nowo\TaskBoardBundle\Support\Text;
use Nowo\TaskBoardBundle\Support\UserLabel;

use function array_filter;
use function array_key_last;
use function array_map;
use function array_unique;
use function array_values;
use function in_array;
use function sort;
use function trim;

/**
 * Since 1.22: applies a re-imported row to the task it matched. Precedence: **the source wins for every field it has a
 * value for** (title, column by status, priority, description, start, due, estimate, tags, assignees); a field the
 * row leaves empty keeps the task's value, so what was set in the application survives. Each change is recorded in
 * the task's history; a row that changes nothing touches nothing (no `updatedAt`, no history), which makes importing
 * the same file twice a no-op.
 */
final readonly class ImportedTaskUpdater
{
    public function __construct(
        private TaskChangeRecorder $changeRecorder = new TaskChangeRecorder(),
    ) {
    }

    /**
     * @param BoardColumn|null $column the column of the row's status (null: no status, or one the board lacks)
     * @param Closure(BoardColumn): int $positionIn the next free position of a column
     * @param list<BoardColumn> $columns the board's columns in order (the last one completes a task)
     *
     * @return bool whether the task changed
     */
    public function apply(Task $task, ImportedTaskDto $row, ?BoardColumn $column, Closure $positionIn, array $columns, object $actor): bool
    {
        $changed = false;

        $title = Text::cut($row->title, Task::TITLE_MAX);
        if ($title !== $task->getTitle()) {
            $this->changeRecorder->recordIfChanged($task, $actor, TaskChangeType::Title, $task->getTitle(), $title);
            $task->setTitle($title);
            $changed = true;
        }

        if ($row->description !== null && $row->description !== $task->getDescription()) {
            $this->changeRecorder->recordIfChanged($task, $actor, TaskChangeType::Description, $task->getDescription(), $row->description);
            $task->setDescription($row->description);
            $changed = true;
        }

        if ($row->hasPriority && $row->priority !== $task->getPriority()) {
            $this->changeRecorder->recordIfChanged($task, $actor, TaskChangeType::Priority, $task->getPriority(), $row->priority);
            $task->setPriority($row->priority);
            $changed = true;
        }

        if ($column instanceof BoardColumn && $column->getId() !== $task->getColumn()?->getId()) {
            $this->changeRecorder->recordIfChanged($task, $actor, TaskChangeType::Column, $task->getColumn(), $column);
            $task->setColumn($column);
            $task->setPosition($positionIn($column));
            $this->syncCompletion($task, $column, $columns, $actor);
            $changed = true;
        }

        if ($row->startAt instanceof DateTimeImmutable && !$this->sameMoment($row->startAt, $task->getStartAt())) {
            $this->changeRecorder->recordIfChanged($task, $actor, TaskChangeType::StartDate, $task->getStartAt(), $row->startAt);
            $task->setStartAt($row->startAt);
            $changed = true;
        }

        if ($row->dueAt instanceof DateTimeImmutable && !$this->sameMoment($row->dueAt, $task->getDueAt())) {
            $this->changeRecorder->recordIfChanged($task, $actor, TaskChangeType::DueDate, $task->getDueAt(), $row->dueAt);
            $task->setDueAt($row->dueAt);
            $changed = true;
        }

        if ($row->estimatedMinutes !== null && $row->estimatedMinutes !== $task->getEstimatedMinutes()) {
            $this->changeRecorder->recordIfChanged($task, $actor, TaskChangeType::EstimatedMinutes, $task->getEstimatedMinutes(), $row->estimatedMinutes);
            $task->setEstimatedMinutes($row->estimatedMinutes);
            $changed = true;
        }

        if ($row->tags !== [] && $this->normalizedTags($row->tags) !== $task->getTags()) {
            $old = $task->getTags();
            $task->setTags($row->tags);
            $this->changeRecorder->recordIfChanged($task, $actor, TaskChangeType::Tags, $old, $task->getTags());
            $changed = true;
        }

        return $changed;
    }

    /**
     * The source's assignees replace the task's (other roles — reviewers, watchers — are left alone); an empty list
     * (no assignee in the row, or none resolved) keeps them.
     *
     * @param list<object> $users
     *
     * @return bool whether the task changed
     */
    public function applyAssignees(Task $task, array $users, object $actor): bool
    {
        if ($users === []) {
            return false;
        }
        $changed = false;
        $present = [];
        foreach ($task->getMembers()->toArray() as $member) {
            if ($member->getMemberRole() !== TaskMemberRole::Assignee) {
                continue;
            }
            if (in_array($member->getUser(), $users, true)) {
                $present[] = $member->getUser();
                continue;
            }
            $task->removeMember($member);
            $this->changeRecorder->record($task, $actor, TaskChangeType::MemberRemoved, UserLabel::of($member->getUser()), null, TaskMemberRole::Assignee->value);
            $changed = true;
        }
        foreach ($users as $user) {
            if (!in_array($user, $present, true)) {
                $task->addMember(new TaskMember($task, $user, TaskMemberRole::Assignee));
                $this->changeRecorder->record($task, $actor, TaskChangeType::MemberAdded, null, UserLabel::of($user), TaskMemberRole::Assignee->value);
                $changed = true;
            }
        }

        return $changed;
    }

    /**
     * @param list<BoardColumn> $columns
     */
    private function syncCompletion(Task $task, BoardColumn $column, array $columns, object $actor): void
    {
        $wasCompleted = $task->isCompleted();
        $last         = $columns !== [] && $columns[array_key_last($columns)]->getId() === $column->getId();
        if ($last && !$wasCompleted) {
            $task->markCompleted();
        } elseif (!$last && $wasCompleted) {
            $task->markIncomplete();
        }
        $this->changeRecorder->recordIfChanged($task, $actor, TaskChangeType::Completed, $wasCompleted, $task->isCompleted());
    }

    /**
     * Two dates as the database keeps them (wall-clock time, no zone).
     */
    private function sameMoment(DateTimeImmutable $date, ?DateTimeImmutable $other): bool
    {
        return $date->format('Y-m-d H:i:s') === $other?->format('Y-m-d H:i:s');
    }

    /**
     * The tags as {@see Task::setTags()} keeps them: trimmed, once, sorted.
     *
     * @param list<string> $tags
     *
     * @return list<string>
     */
    private function normalizedTags(array $tags): array
    {
        $normalized = array_values(array_unique(array_filter(array_map(trim(...), $tags), static fn (string $tag): bool => $tag !== '')));
        sort($normalized);

        return $normalized;
    }
}
