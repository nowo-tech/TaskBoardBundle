<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Api\Value;

use DateTimeImmutable;

/**
 * Since 2.2.0 (spec 213): a time entry to log (`POST /v2/team/{team_id}/time_entries`).
 */
final readonly class TaskApiNewTimeEntry
{
    public function __construct(
        public string $teamId,
        public DateTimeImmutable $start,
        public int $durationSeconds,
        public ?string $description = null,
    ) {
    }
}
