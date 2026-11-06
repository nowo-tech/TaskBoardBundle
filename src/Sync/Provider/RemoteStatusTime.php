<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Sync\Provider;

use DateTimeImmutable;

/**
 * Since 1.27.0 (TB-SYNC-3): one status of a task's history ("time in status") — the status, since when, and the minutes
 * spent in it. Read-only on the task.
 */
final readonly class RemoteStatusTime
{
    public function __construct(
        public string $status,
        public ?DateTimeImmutable $since,
        public int $minutes,
        public bool $current = false,
    ) {
    }
}
