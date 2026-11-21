<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Bridge\TimeTrack;

use Nowo\TaskBoardBundle\Service\TaskTimeBillableDefaultProviderInterface;
use Nowo\TimeTrackBundle\Integration\TimeEntryBillableDefaultProviderInterface;
use Nowo\TimeTrackBundle\Timesheet\TimesheetScopePolicyInterface;
use Nowo\TimeTrackBundle\Timesheet\TimesheetScopeProviderInterface;

/**
 * Since 2.12.0 (TimeTrackBundle >= 1.21): the default `billable` of a manual entry is TimeTrack's host default, asked with
 * the task's TimeTrack tenant scope (`''` when TimeTrack has no scope provider or the user is not a security user), so one
 * host provider serves TimeTrack's entries and TaskBoard's manual ones. No default while TimeTrackBundle is installed but
 * not enabled.
 */
final readonly class TimeTrackTaskTimeBillableDefaultProvider implements TaskTimeBillableDefaultProviderInterface
{
    public function __construct(
        private ?TimeEntryBillableDefaultProviderInterface $defaults = null,
        // Since 2.28.0 also TimeTrack's framework-free scope policy.
        private TimesheetScopeProviderInterface|TimesheetScopePolicyInterface|null $scopes = null,
    ) {
    }

    public function defaultFor(object $user, string $taskId, ?string $boardId): ?bool
    {
        if (!$this->defaults instanceof TimeEntryBillableDefaultProviderInterface) {
            return null;
        }
        $scopeKey = TimeTrackScopeKeys::forTask($this->scopes, $user, $taskId, $boardId);

        return $this->defaults->defaultFor($scopeKey ?? '', $taskId);
    }
}
