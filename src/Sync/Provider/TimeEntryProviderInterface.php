<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Sync\Provider;

/**
 * Since 1.25: read-only access to tracked time (optional; {@see ProviderCapabilities::$timeEntries}).
 */
interface TimeEntryProviderInterface
{
    /**
     * Entries of one window and location. Running timers are returned with `running` true (the caller skips them).
     *
     * @return iterable<RemoteTimeEntry>
     */
    public function fetchTimeEntries(ProviderCredential $credential, TimeEntryQuery $query): iterable;

    /**
     * Legacy per-task intervals of every user; null when the provider no longer serves them.
     *
     * @return iterable<RemoteTimeEntry>|null
     */
    public function fetchTaskTimeIntervals(ProviderCredential $credential, string $taskId): ?iterable;
}
