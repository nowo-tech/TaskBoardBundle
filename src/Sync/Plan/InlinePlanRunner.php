<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Sync\Plan;

/**
 * Since 3.2.0 (CU-2): the default plan runner — one slice in the request (at most `sync.plans.inline_slice_seconds`,
 * default 20 s, below `max_execution_time`), and the progress page advances the plan, one slice per poll. Fine for demos
 * and small imports; a host with a worker sets `sync.plans.messenger.enabled` or its own `sync.plans.runner`.
 */
final readonly class InlinePlanRunner implements ImportPlanRunnerInterface
{
    public function __construct(
        private ImportPlanRunner $runner,
        private int $sliceSeconds = 20,
    ) {
    }

    public function dispatch(PlanRunRequest $request): void
    {
        $this->runner->runSlice($request->planId, $this->sliceSeconds);
    }

    public function advancesOnPoll(): bool
    {
        return true;
    }
}
