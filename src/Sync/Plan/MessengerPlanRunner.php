<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Sync\Plan;

use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\DelayStamp;

/**
 * Since 3.2.0 (CU-2, Q-CU-9, `sync.plans.messenger.enabled`, off by default): queues {@see RunImportPlanSlice} on the
 * host's bus (with a {@see DelayStamp} when the plan is due later); {@see RunImportPlanSliceHandler} runs the slice and
 * queues the next one while the plan is due.
 */
final readonly class MessengerPlanRunner implements ImportPlanRunnerInterface
{
    public function __construct(
        private MessageBusInterface $bus,
    ) {
    }

    public function dispatch(PlanRunRequest $request): void
    {
        $this->bus->dispatch(new RunImportPlanSlice($request->planId, $request->actorId), $request->delaySeconds > 0 ? [new DelayStamp($request->delaySeconds * 1000)] : []);
    }

    public function advancesOnPoll(): bool
    {
        return false;
    }
}
