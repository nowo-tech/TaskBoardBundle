<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Sync\Provider;

use Nowo\TaskBoardBundle\Sync\Exception\ProviderException;

/**
 * Since 1.27.0 (TB-SYNC-3): a provider that serves a task's status history ("time in status"). Called for the tasks a
 * run creates or changes, at most `sync.status_history.max_calls_per_run` times per run.
 */
interface StatusHistoryProviderInterface extends TaskProviderInterface
{
    /**
     * Oldest first; null when the provider does not serve it for this task (e.g. the ClickApp is off).
     *
     * @throws ProviderException
     *
     * @return list<RemoteStatusTime>|null
     */
    public function fetchStatusHistory(ProviderCredential $credential, string $taskId): ?array;
}
