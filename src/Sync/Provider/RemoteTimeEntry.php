<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Sync\Provider;

use DateTimeImmutable;

/**
 * Since 1.25: one tracked-time entry of the provider. A running timer has `running` true (its duration is unknown);
 * the description is plain text, sanitised but never truncated.
 */
final readonly class RemoteTimeEntry
{
    /**
     * @param list<string> $tags
     */
    public function __construct(
        public string $id,
        public ?string $taskId,
        public RemotePersonRef $person,
        public DateTimeImmutable $start,
        public ?DateTimeImmutable $end,
        public int $durationSeconds,
        public bool $running = false,
        public ?bool $billable = null,
        public ?string $description = null,
        public array $tags = [],
        public ?DateTimeImmutable $remoteUpdatedAt = null,
    ) {
    }
}
