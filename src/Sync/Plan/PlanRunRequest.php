<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Sync\Plan;

/**
 * Since 3.2.0 (CU-2): what {@see ImportPlanRunnerInterface} is asked to run — ids only (never a credential), so it can
 * travel in a Messenger message. `$delaySeconds` is how long a queued runner waits before the slice.
 */
final readonly class PlanRunRequest
{
    public function __construct(
        public string $planId,
        public ?string $actorId,
        public int $delaySeconds = 0,
    ) {
    }
}
