<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\View;

use DateTimeImmutable;

/**
 * Since 2.29.0: one completed task with when it was created and completed (lead time samples of
 * {@see \Nowo\TaskBoardBundle\Service\TaskFlowStatsInterface}).
 */
final readonly class TaskCompletionSample
{
    public function __construct(
        public string $taskId,
        public string $boardId,
        public string $tenantKey,
        public DateTimeImmutable $createdAt,
        public DateTimeImmutable $completedAt,
    ) {
    }
}
