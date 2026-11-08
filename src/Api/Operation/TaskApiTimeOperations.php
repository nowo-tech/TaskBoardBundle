<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Api\Operation;

use Nowo\TaskBoardBundle\Api\Hook\TaskApiPlanGateInterface;
use Nowo\TaskBoardBundle\Api\Hook\TaskApiTimeEntriesInterface;
use Nowo\TaskBoardBundle\Api\Hook\TaskApiUsersInterface;
use Nowo\TaskBoardBundle\Api\Hook\TaskApiWorkspaceInterface;
use Nowo\TaskBoardBundle\Api\Security\TaskApiGuard;
use Nowo\TaskBoardBundle\Api\TaskApiCapability;
use Nowo\TaskBoardBundle\Api\TaskApiInput;
use Nowo\TaskBoardBundle\Api\TaskApiPrincipal;
use Nowo\TaskBoardBundle\Api\TaskApiRefusal;
use Nowo\TaskBoardBundle\Api\Value\TaskApiNewTimeEntry;
use Nowo\TaskBoardBundle\Api\Value\TaskApiTimeEntry;
use Nowo\TaskBoardBundle\Validation\TaskConstraints;

use function intdiv;
use function mb_strlen;

/**
 * Since 2.2.0 (spec 213): time through the host's time store. A token logs its own person's time only. Deleting time is
 * never offered (owner, Q-O7): `DELETE …/time_entries/{id}` is refused before anything is looked up.
 */
final readonly class TaskApiTimeOperations
{
    /** ClickUp's time entry fields with no equivalent here. */
    public const NOT_SUPPORTED = ['billable', 'tags', 'stop', 'end', 'created_with', 'custom_task_ids', 'team_id'];

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
     */
    public function logTime(TaskApiPrincipal $principal, string $teamId, TaskApiInput $input): TaskApiTimeEntry
    {
        $this->audit->requireListener();
        if (!$this->planGate->allowsTasks($principal->user, null)) {
            throw TaskApiRefusal::planRefused();
        }
        $this->requireTeam($principal, $teamId);
        $taskId = $input->string('tid');
        $task   = $taskId === null ? null : $this->guard->task($principal, $taskId, TaskApiCapability::WorkOnTask);
        if ($task === null) {
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

        $entry = $this->timeEntries->log($principal, $task, new TaskApiNewTimeEntry($teamId, $start, intdiv($duration, 1000), $description));
        $this->audit->written($principal, 'time.log', $task?->getBoard(), $task?->getId(), ['entry' => $entry->id]);

        return $entry;
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
