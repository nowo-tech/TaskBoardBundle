<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Api\Operation;

use Nowo\TaskBoardBundle\Api\Hook\TaskApiPlanGateInterface;
use Nowo\TaskBoardBundle\Api\Hook\TaskApiTimeEntriesInterface;
use Nowo\TaskBoardBundle\Api\Hook\TaskApiTimeEntryBillableInterface;
use Nowo\TaskBoardBundle\Api\Hook\TaskApiUsersInterface;
use Nowo\TaskBoardBundle\Api\Hook\TaskApiWorkspaceInterface;
use Nowo\TaskBoardBundle\Api\Security\TaskApiGuard;
use Nowo\TaskBoardBundle\Api\TaskApiCapability;
use Nowo\TaskBoardBundle\Api\TaskApiInput;
use Nowo\TaskBoardBundle\Api\TaskApiPrincipal;
use Nowo\TaskBoardBundle\Api\TaskApiRefusal;
use Nowo\TaskBoardBundle\Api\Value\TaskApiNewTimeEntry;
use Nowo\TaskBoardBundle\Api\Value\TaskApiTimeEntry;
use Nowo\TaskBoardBundle\Entity\Task;
use Nowo\TaskBoardBundle\Entity\TaskBoard;
use Nowo\TaskBoardBundle\Validation\TaskConstraints;

use function intdiv;
use function mb_strlen;

/**
 * Since 2.2.0 (spec 213): time through the host's time store. A token logs its own person's time only. Deleting time is
 * never offered (owner, Q-O7): `DELETE …/time_entries/{id}` is refused before anything is looked up. Since 2.4.0 a time
 * log on a task asks the time-tracking decision ({@see TaskApiGuard::taskToTrackTime()}) instead of the edit right.
 */
final readonly class TaskApiTimeOperations
{
    /** ClickUp's time entry fields with no equivalent here. */
    public const NOT_SUPPORTED = ['tags', 'stop', 'end', 'created_with', 'custom_task_ids', 'team_id'];

    /** Since 2.12.0: ClickUp's time entry update fields other than `billable` (not changed here). */
    public const UPDATE_NOT_SUPPORTED = ['description', 'tags', 'tag_action', 'start', 'end', 'tid', 'duration', 'custom_task_ids', 'team_id'];

    public function __construct(
        private TaskApiGuard $guard,
        private TaskApiAudit $audit,
        private TaskApiPlanGateInterface $planGate,
        private TaskApiWorkspaceInterface $workspace,
        private TaskApiUsersInterface $users,
        private TaskApiTimeEntriesInterface $timeEntries,
    ) {
    }

    /**
     * `POST /v2/team/{team_id}/time_entries`: `start` and `duration` in milliseconds, `tid` (a task), `description`.
     *
     * @throws TaskApiRefusal
     *
     * @return array{0: ?Task, 1: TaskApiTimeEntry}
     */
    public function logTime(TaskApiPrincipal $principal, string $teamId, TaskApiInput $input): array
    {
        $this->audit->requireListener();
        if (!$this->planGate->allowsTasks($principal->user, null)) {
            throw TaskApiRefusal::planRefused();
        }
        $this->requireTeam($principal, $teamId);
        $taskId = $input->string('tid');
        // Since 2.4.0 (D-20261108-13): the time-tracking decision, not the edit right (which stays its fallback).
        $task = $taskId === null ? null : $this->guard->taskToTrackTime($principal, $taskId);
        if (!$task instanceof Task) {
            $this->guard->requireLevel($principal, TaskApiCapability::WorkOnTask);
        }
        $assignee = $input->string('assignee');
        if ($assignee !== null && $assignee !== $this->users->apiId($principal->user)) {
            // A token logs its own person's time, never someone else's.
            throw TaskApiRefusal::levelRefused();
        }
        $start    = $input->date('start') ?? throw new TaskApiRefusal(400, 'INPUT_002', 'Invalid start');
        $duration = $input->int('duration') ?? throw new TaskApiRefusal(400, 'INPUT_002', 'Invalid duration');
        if ($duration < 60000 || $duration > TaskConstraints::MAX_MINUTES * 60000) {
            throw new TaskApiRefusal(400, 'INPUT_002', 'Invalid duration');
        }
        $description = $input->string('description');
        if ($description !== null && mb_strlen($description) > TaskConstraints::NOTE_MAX) {
            throw new TaskApiRefusal(400, 'INPUT_002', 'Invalid description');
        }

        // Since 2.12.0: `billable` (absent or null = the host default).
        $billable = $input->bool('billable');

        $entry = $this->timeEntries->log($principal, $task, new TaskApiNewTimeEntry($teamId, $start, intdiv($duration, 1000), $description, $billable));
        $this->audit->written($principal, 'time.log', $task?->getBoard(), $task?->getId(), ['entry' => $entry->id]);

        return [$task, $entry];
    }

    /**
     * Since 2.12.0: `PUT /v2/team/{team_id}/time_entries/{timer_id}` with `billable` (required, boolean) — the flag of one
     * of the token's own entries; the other update fields are not changed (answered as warnings). Needs a time store with
     * {@see TaskApiTimeEntryBillableInterface} (403 `TIME_003` otherwise).
     *
     * @throws TaskApiRefusal
     *
     * @return array{0: ?TaskBoard, 1: TaskApiTimeEntry} the entry's board (audit row, idempotency target) and the entry
     */
    public function updateTime(TaskApiPrincipal $principal, string $teamId, string $entryId, TaskApiInput $input): array
    {
        $this->audit->requireListener();
        if (!$this->planGate->allowsTasks($principal->user, null)) {
            throw TaskApiRefusal::planRefused();
        }
        $this->requireTeam($principal, $teamId);
        $billable = $input->bool('billable') ?? throw new TaskApiRefusal(400, 'INPUT_002', 'Invalid billable');
        if (!$this->timeEntries instanceof TaskApiTimeEntryBillableInterface) {
            throw new TaskApiRefusal(403, 'TIME_003', 'Time entries cannot be changed through this store');
        }

        // @igor-ignore - The time store writes through the bundle's billable service (Doctrine); ManualTaskApiTimeEntries is final readonly and keeps nothing.
        $entry = $this->timeEntries->setBillable($principal, $entryId, $billable);
        // Audit MV-B-I2: the audit row names the entry's board (the store already checked the task with the same rule).
        $board = $entry->taskId === null ? null : $this->guard->task($principal, $entry->taskId, TaskApiCapability::WorkOnTask)->getBoard();
        $this->audit->written($principal, 'time.billable', $board, $entry->taskId, ['entry' => $entry->id], ['billable']);

        return [$board, $entry];
    }

    /**
     * @throws TaskApiRefusal
     */
    private function requireTeam(TaskApiPrincipal $principal, string $teamId): void
    {
        foreach ($this->workspace->teams($principal) as $team) {
            if ($team->id === $teamId) {
                return;
            }
        }

        throw TaskApiRefusal::notFound();
    }
}
