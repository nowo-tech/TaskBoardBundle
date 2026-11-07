<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Api\Value;

use DateTimeImmutable;

/**
 * Since 2.2.0 (spec 213): a filter on the time entries of a team (`GET /v2/team/{team_id}/time_entries`).
 */
final readonly class TaskApiTimeQuery
{
    /**
     * @param list<string> $assigneeIds API ids; empty: the token's own
     */
    public function __construct(
        public string $teamId,
        public ?DateTimeImmutable $from = null,
        public ?DateTimeImmutable $to = null,
        public array $assigneeIds = [],
        public ?string $taskId = null,
    ) {
    }
}
