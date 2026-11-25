<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Sync\Plan;

use DateTimeImmutable;
use Nowo\TaskBoardBundle\Sync\Repository\ImportPlanStore;
use Psr\Clock\ClockInterface;
use Symfony\Component\Clock\NativeClock;

use function in_array;
use function max;

/**
 * Since 3.2.0 (CU-2, Q-CU-9): handles {@see RunImportPlanSlice} — one slice of at most `sync.plans.messenger.slice_seconds`
 * (default 120 s, below the worker's time limit), then the next message while the plan is due: at once after a slice
 * that used its time, after the wait (with jitter) when rate-limited or after an outage. A slice that did not run (the
 * plan ended, is not due, or another worker holds it) and a plan waiting for its people (no due time) queue nothing. The actor is checked again at every slice.
 */
final readonly class RunImportPlanSliceHandler
{
    public function __construct(
        private ImportPlanRunner $runner,
        private ImportPlanStore $store,
        private MessengerPlanRunner $dispatcher,
        private ClockInterface $clock = new NativeClock(),
        private int $sliceSeconds = 120,
    ) {
    }

    public function __invoke(RunImportPlanSlice $message): void
    {
        $status   = $this->runner->runSlice($message->planId, $message->actorId, $this->sliceSeconds);
        $resumeAt = $this->store->find($message->planId)?->getResumeAt();
        if (!in_array($status, [ImportPlanStatus::Paused, ImportPlanStatus::RateLimited], true) || !$resumeAt instanceof DateTimeImmutable) {
            return;
        }
        $this->dispatcher->dispatch(new PlanRunRequest($message->planId, $message->actorId, max(0, $resumeAt->getTimestamp() - $this->clock->now()->getTimestamp())));
    }
}
