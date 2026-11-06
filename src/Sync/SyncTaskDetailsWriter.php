<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Sync;

use DateTimeImmutable;
use Nowo\TaskBoardBundle\Entity\Task;
use Nowo\TaskBoardBundle\Sync\Entity\TaskSyncItem;
use Nowo\TaskBoardBundle\Sync\Entity\TaskSyncPerson;
use Nowo\TaskBoardBundle\Sync\Exception\ProviderErrorCode;
use Nowo\TaskBoardBundle\Sync\Exception\ProviderException;
use Nowo\TaskBoardBundle\Sync\People\PeopleSession;
use Nowo\TaskBoardBundle\Sync\People\PersonToken;
use Nowo\TaskBoardBundle\Sync\Provider\RemoteStatusTime;
use Nowo\TaskBoardBundle\Sync\Provider\RemoteTask;
use Nowo\TaskBoardBundle\Sync\Provider\RemoteTaskDetails;
use Nowo\TaskBoardBundle\Sync\Provider\StatusHistoryProviderInterface;

use function array_map;
use function in_array;
use function is_array;

use const DATE_ATOM;

/**
 * Since 1.27.0 (TB-SYNC-3): the rest of a provider task, read-only on the synced task — custom id, points, done /
 * closed dates (the done date also becomes the task's completion date), start / due time flags, location path,
 * estimates per assignee (people as references: names stay in the people directory, so forgetting a person is enough),
 * recurrence and the status history ("time in status", at most `sync.status_history.max_calls_per_run` requests per
 * run) — plus the completeness counters (`keys.<answer key>`, at most {@see self::MAX_KEYS} keys per run).
 */
final readonly class SyncTaskDetailsWriter
{
    public const STAT_STATUS_CALLS = 'statusHistory.calls';

    public const STAT_STATUS_FAILED = 'statusHistory.failed';

    public const STAT_STATUS_FORBIDDEN = 'statusHistory.forbidden';

    public const STAT_STATUS_SKIPPED = 'statusHistory.skipped';

    /** The remote date of the task when its detail (files) was last read. */
    public const META_ATTACHMENTS_READ = 'attachmentsRead';

    private const BASELINE_COMPLETED = 'completed';

    /** Remote keys are untrusted: the stats of a run stay bounded. */
    public const MAX_KEYS = 200;

    /** Passing errors: the run handles them as usual and the task is retried. Any other keeps the last history. */
    private const RETRIED = [ProviderErrorCode::AuthFailed, ProviderErrorCode::RateLimited, ProviderErrorCode::Timeout, ProviderErrorCode::Transport, ProviderErrorCode::ServerError];

    public function __construct(private int $maxStatusCalls = 200)
    {
    }

    /** Counts the answer keys of a task the run read. */
    public function countKeys(SyncRunContext $ctx, RemoteTask $remote): void
    {
        foreach ($remote->presentKeys as $key) {
            if (isset($ctx->stats['keys.' . $key])) {
                $ctx->count('keys.' . $key);
            } elseif (($ctx->stats['completeness.keys'] ?? 0) < self::MAX_KEYS) {
                $ctx->count('completeness.keys');
                $ctx->count('keys.' . $key);
            }
        }
    }

    /**
     * @throws ProviderException a passing error of the status history
     */
    public function write(SyncRunContext $ctx, TaskSyncItem $item, Task $task, RemoteTask $remote, ?PeopleSession $session = null, bool $attachmentsRead = false): void
    {
        $details   = $remote->details ?? new RemoteTaskDetails();
        $meta      = $details->toArray();
        $estimates = array_map(static fn (array $estimate): array => [
            'person'  => PersonToken::of($session, $estimate['person'], TaskSyncPerson::KIND_ASSIGNEE),
            'minutes' => $estimate['minutes'],
        ], $details->estimates);
        if ($estimates !== []) {
            $meta['estimates'] = $estimates;
        }
        $history = $this->history($ctx, $item);
        if ($history !== null && $history !== []) {
            $meta['statusHistory'] = $history;
        }
        $read = $attachmentsRead ? $remote->remoteUpdatedAt->getTimestamp() : ($item->getRemoteMeta()[self::META_ATTACHMENTS_READ] ?? null);
        if ($read !== null) {
            $meta[self::META_ATTACHMENTS_READ] = $read;
        }
        $changedId   = $item->setCustomId($details->customId);
        $changedMeta = $item->setRemoteMeta($meta);
        if ($changedId || $changedMeta) {
            $ctx->count('details.updated');
        }
        $this->completion($ctx, $item, $task, $details->doneAt ?? $details->closedAt);
    }

    /**
     * The provider's done (else closed) date is when the task was completed — unless the date here was set here (audit
     * GW-L5): applied only to a task the sync completed, or whose date has not changed since the last time.
     */
    private function completion(SyncRunContext $ctx, TaskSyncItem $item, Task $task, ?DateTimeImmutable $doneAt): void
    {
        if (!$doneAt instanceof DateTimeImmutable || !$task->isCompleted()) {
            return;
        }
        $local = (string) $task->getCompletedAt()?->getTimestamp();
        $base  = $item->baseline(self::BASELINE_COMPLETED);
        if (!isset($ctx->completedBySync[$task->getId()]) && ($base === null || $base[1] !== $local)) {
            $ctx->count('details.completionKept');

            return;
        }
        if ($local !== (string) $doneAt->getTimestamp()) {
            $task->markCompleted($doneAt);
        }
        $item->setBaseline(self::BASELINE_COMPLETED, (string) $doneAt->getTimestamp(), (string) $doneAt->getTimestamp());
    }

    /**
     * The history to keep: fresh when the budget allows and the provider serves it, else the last one.
     *
     * @return array<mixed>|null
     */
    private function history(SyncRunContext $ctx, TaskSyncItem $item): ?array
    {
        $last     = $item->getRemoteMeta()['statusHistory'] ?? null;
        $last     = is_array($last) ? $last : null;
        $provider = $ctx->provider;
        if (!$provider instanceof StatusHistoryProviderInterface || ($ctx->stats[self::STAT_STATUS_CALLS] ?? 0) >= $this->maxStatusCalls) {
            return $last;
        }
        // A token that may not read it never will: one refusal stops the requests of the whole run.
        if ($ctx->stat(self::STAT_STATUS_FORBIDDEN) > 0) {
            $ctx->count(self::STAT_STATUS_SKIPPED);

            return $last;
        }
        $ctx->count(self::STAT_STATUS_CALLS);
        try {
            $statuses = $provider->fetchStatusHistory($ctx->credential, $item->getExternalId());
        } catch (ProviderException $exception) {
            if (in_array($exception->errorCode, self::RETRIED, true)) {
                throw $exception;
            }
            if ($exception->errorCode === ProviderErrorCode::Forbidden) {
                $ctx->count(self::STAT_STATUS_FORBIDDEN);

                return $last;
            }
            // Read-only extra (e.g. ClickUp answers 400 when the ClickApp is off): never fails a run.
            $ctx->count(self::STAT_STATUS_FAILED);

            return $last;
        }

        return $statuses === null ? $last : array_map(static fn (RemoteStatusTime $status): array => [
            'status'  => $status->status,
            'since'   => $status->since?->format(DATE_ATOM),
            'minutes' => $status->minutes,
            'current' => $status->current,
        ], $statuses);
    }
}
