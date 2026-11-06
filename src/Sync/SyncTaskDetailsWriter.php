<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Sync;

use DateTimeImmutable;
use Nowo\TaskBoardBundle\Entity\Task;
use Nowo\TaskBoardBundle\Sync\Entity\TaskSyncItem;
use Nowo\TaskBoardBundle\Sync\Provider\RemoteStatusTime;
use Nowo\TaskBoardBundle\Sync\Provider\RemoteTask;
use Nowo\TaskBoardBundle\Sync\Provider\RemoteTaskDetails;
use Nowo\TaskBoardBundle\Sync\Provider\StatusHistoryProviderInterface;

use function array_map;

use const DATE_ATOM;

/**
 * Since 1.27.0 (TB-SYNC-3): the rest of a provider task, read-only on the synced task — custom id, points, done /
 * closed dates (the done date also becomes the task's completion date), start / due time flags, location path,
 * estimates per assignee, recurrence and the status history ("time in status", at most
 * `sync.status_history.max_calls_per_run` requests per run) — plus the completeness counters (`keys.<answer key>`).
 */
final readonly class SyncTaskDetailsWriter
{
    public const STAT_STATUS_CALLS = 'statusHistory.calls';

    public function __construct(private int $maxStatusCalls = 200)
    {
    }

    public function write(SyncRunContext $ctx, TaskSyncItem $item, Task $task, RemoteTask $remote): void
    {
        foreach ($remote->presentKeys as $key) {
            $ctx->count('keys.' . $key);
        }
        $details  = $remote->details ?? new RemoteTaskDetails();
        $meta     = $details->toArray();
        $history  = $item->getRemoteMeta()['statusHistory'] ?? null;
        $provider = $ctx->provider;
        if ($provider instanceof StatusHistoryProviderInterface && ($ctx->stats[self::STAT_STATUS_CALLS] ?? 0) < $this->maxStatusCalls) {
            $ctx->count(self::STAT_STATUS_CALLS);
            $statuses = $provider->fetchStatusHistory($ctx->credential, $item->getExternalId());
            $history  = $statuses === null ? $history : array_map(static fn (RemoteStatusTime $status): array => [
                'status'  => $status->status,
                'since'   => $status->since?->format(DATE_ATOM),
                'minutes' => $status->minutes,
                'current' => $status->current,
            ], $statuses);
        }
        if ($history !== null && $history !== []) {
            $meta['statusHistory'] = $history;
        }
        if ($item->setRemoteMeta($meta)) {
            $ctx->count('details.updated');
        }
        // The provider's done (else closed) date is when the task was completed.
        $doneAt = $details->doneAt ?? $details->closedAt;
        if ($doneAt instanceof DateTimeImmutable && $task->isCompleted() && $task->getCompletedAt()?->getTimestamp() !== $doneAt->getTimestamp()) {
            $task->markCompleted($doneAt);
        }
    }
}
