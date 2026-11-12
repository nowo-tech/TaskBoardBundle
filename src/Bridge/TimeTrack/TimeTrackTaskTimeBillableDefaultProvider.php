<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Bridge\TimeTrack;

use Nowo\TaskBoardBundle\Service\TaskTimeBillableDefaultProviderInterface;
use Nowo\TimeTrackBundle\Integration\TimeEntryBillableDefaultProviderInterface;
use Nowo\TimeTrackBundle\Timesheet\TimesheetScopeProviderInterface;
use Symfony\Component\Security\Core\User\UserInterface;

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
        private ?TimesheetScopeProviderInterface $scopes = null,
    ) {
    }

    public function defaultFor(object $user, string $taskId, ?string $boardId): ?bool
    {
        if (!$this->defaults instanceof TimeEntryBillableDefaultProviderInterface) {
            return null;
        }
        $scopeKey = $user instanceof UserInterface ? $this->scopes?->scopeKeyForTask($user, $taskId, $boardId) : null;

        return $this->defaults->defaultFor($scopeKey ?? '', $taskId);
    }
}
