<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Sync\Plan;

/**
 * Since 3.2.0 (CU-2): runs the slices of a started import plan. The default {@see InlinePlanRunner} runs one slice per
 * request (the progress page advances the plan, one slice per poll); the optional {@see MessengerPlanRunner}
 * (`sync.plans.messenger.enabled`) queues {@see RunImportPlanSlice} messages. A host sets `sync.plans.runner` to its own.
 */
interface ImportPlanRunnerInterface
{
    public function dispatch(PlanRunRequest $request): void;

    /** Whether the progress page advances the plan itself, one slice per poll (the inline runner). */
    public function advancesOnPoll(): bool;
}
