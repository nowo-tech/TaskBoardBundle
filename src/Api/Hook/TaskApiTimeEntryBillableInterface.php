<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Api\Hook;

use Nowo\TaskBoardBundle\Api\TaskApiPrincipal;
use Nowo\TaskBoardBundle\Api\TaskApiRefusal;
use Nowo\TaskBoardBundle\Api\Value\TaskApiTimeEntry;

/**
 * Since 2.12.0 (Beacon D-20261111-12): optional capability of the time store (`api.hooks.time_entries`) — the `billable`
 * flag of one of the token's own entries (`PUT /v2/team/{team_id}/time_entries/{timer_id}`). A store without it answers
 * that route with 403 `TIME_003`. {@see ManualTaskApiTimeEntries} has it. Own entries only (another person's entry is
 * 404); the board-manager path is the task page. The edit right on the task still applies (403), and never in a locked or
 * submitted period (409 `TIME_004`).
 */
interface TaskApiTimeEntryBillableInterface
{
    /**
     * @throws TaskApiRefusal
     */
    public function setBillable(TaskApiPrincipal $principal, string $entryId, bool $billable): TaskApiTimeEntry;
}
