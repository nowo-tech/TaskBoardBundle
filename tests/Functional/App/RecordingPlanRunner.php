<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Tests\Functional\App;

use Nowo\TaskBoardBundle\Sync\Plan\ImportPlanRunnerInterface;
use Nowo\TaskBoardBundle\Sync\Plan\PlanRunRequest;

/**
 * Since 3.2.0: a host's plan runner that only records what it is asked (used when a test sets sync.plans.runner), so a
 * test runs the slices itself.
 */
final class RecordingPlanRunner implements ImportPlanRunnerInterface
{
    /** @var list<PlanRunRequest> */
    public static array $requests = [];

    public static bool $onPoll = false;

    public function dispatch(PlanRunRequest $request): void
    {
        self::$requests[] = $request;
    }

    public function advancesOnPoll(): bool
    {
        return self::$onPoll;
    }

    public static function reset(): void
    {
        self::$requests = [];
        self::$onPoll   = false;
    }
}
