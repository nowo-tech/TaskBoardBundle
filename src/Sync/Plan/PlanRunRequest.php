<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Sync\Plan;

/**
 * Since 3.2.0 (CU-2): what {@see ImportPlanRunnerInterface} is asked to run — the plan's id only (never a credential,
 * never an actor: a slice always runs as the user who started the plan, audit ZBY-M1), so it can travel in a Messenger
 * message. `$delaySeconds` is how long a queued runner waits before the slice.
 */
final readonly class PlanRunRequest
{
    public function __construct(
        public string $planId,
        public int $delaySeconds = 0,
    ) {
    }
}
