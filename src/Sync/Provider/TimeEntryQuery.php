<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Sync\Provider;

use DateTimeImmutable;

/**
 * Since 1.25: one window of tracked time to read. `assigneeIds` null = the token user's own entries; the ids always
 * come from the people directory, never from user input.
 */
final readonly class TimeEntryQuery
{
    /**
     * @param list<string>|null $assigneeIds
     */
    public function __construct(
        public string $workspaceId,
        public RemoteLocation $location,
        public DateTimeImmutable $from,
        public DateTimeImmutable $to,
        public ?array $assigneeIds = null,
    ) {
    }
}
