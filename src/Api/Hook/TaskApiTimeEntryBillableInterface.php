<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Api\Hook;

use Nowo\TaskBoardBundle\Api\TaskApiPrincipal;
use Nowo\TaskBoardBundle\Api\TaskApiRefusal;
use Nowo\TaskBoardBundle\Api\Value\TaskApiTimeEntry;

/**
 * Since 2.12.0 (Beacon D-20261111-12): optional capability of the time store (`api.hooks.time_entries`) — the `billable`
 * flag of one of the token's own entries (`PUT /v2/team/{team_id}/time_entries/{timer_id}`). A store without it answers
 * that route with 403 `TIME_003`. {@see ManualTaskApiTimeEntries} has it. The store applies the same rules as the task
 * page: its author or a manager of the board, and never in a locked period (409 `TIME_004`).
 */
interface TaskApiTimeEntryBillableInterface
{
    /**
     * @throws TaskApiRefusal
     */
    public function setBillable(TaskApiPrincipal $principal, string $entryId, bool $billable): TaskApiTimeEntry;
}
