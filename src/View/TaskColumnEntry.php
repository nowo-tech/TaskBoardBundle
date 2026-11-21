<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\View;

use DateTimeImmutable;
use Nowo\TaskBoardBundle\Service\TaskFlowStatsInterface;

/**
 * Since 2.29.0: the first time a task entered a column (by the column's name in the task history), for
 * {@see TaskFlowStatsInterface::firstEntries()}.
 */
final readonly class TaskColumnEntry
{
    public function __construct(
        public string $taskId,
        public string $tenantKey,
        public DateTimeImmutable $enteredAt,
    ) {
    }
}
