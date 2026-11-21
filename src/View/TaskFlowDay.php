<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\View;

/**
 * Since 2.29.0: tasks created and completed in one tenant on one day ({@see \Nowo\TaskBoardBundle\Service\TaskFlowStatsInterface}).
 */
final readonly class TaskFlowDay
{
    /**
     * @param string $day `Y-m-d` in the time zone of the query
     */
    public function __construct(
        public string $tenantKey,
        public string $day,
        public int $created,
        public int $completed,
    ) {
    }
}
