<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Service;

/**
 * Since 2.12.0 (Beacon D-20261111-12): the `billable` value of a manual time entry logged without one (the *Log time*
 * dialog with the switch untouched is not "without one": the dialog shows this default and submits it; the Task API's
 * `POST time_entries` without `billable` is).
 *
 * No framework type (REQ-ARCH-001 rule 3): the user is the host's user object, the task and board are ids. Return null to
 * keep the behaviour before 2.12 (the flag stays unset). The default ({@see NullTaskTimeBillableDefaultProvider}) answers
 * null; with TimeTrackBundle >= 1.21 installed the container aliases this port to a bridge that asks TimeTrack's
 * `TimeEntryBillableDefaultProviderInterface`, so one host provider serves both bundles. Alias it to your own service to
 * answer differently. Keep it fast, read-only and free of request state (worker mode).
 */
interface TaskTimeBillableDefaultProviderInterface
{
    public function defaultFor(object $user, string $taskId, ?string $boardId): ?bool;
}
