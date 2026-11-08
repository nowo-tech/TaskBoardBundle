<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Tests\Functional\App;

use Nowo\TaskBoardBundle\Entity\TaskBoard;
use Nowo\TaskBoardBundle\Sync\Hook\LimitRefusal;
use Nowo\TaskBoardBundle\Sync\Hook\SyncLimitCheckerInterface;

/**
 * Since 1.23: the host's quotas of the functional kernel — refuses new tasks or bytes when a test says so.
 */
final class TestSyncLimitChecker implements SyncLimitCheckerInterface
{
    public static bool $refuseTasks = false;

    public static bool $refuseBytes = false;

    /** Since 2.2.1: with $refuseTasks, the first calls this allows before refusing (the limit changing between two asks). */
    public static int $allowedTaskCalls = 0;

    /** @var list<array{0: int, 1: int}> */
    public static array $calls = [];

    public function check(TaskBoard $board, int $newTasks, int $newBytes): ?LimitRefusal
    {
        self::$calls[] = [$newTasks, $newBytes];
        if ($newTasks > 0 && self::$refuseTasks && self::$allowedTaskCalls-- <= 0) {
            return new LimitRefusal('active_tasks', overTasks: $newTasks);
        }
        if ($newBytes > 0 && self::$refuseBytes) {
            return new LimitRefusal('storage', overBytes: $newBytes);
        }

        return null;
    }

    public static function reset(): void
    {
        self::$refuseTasks      = false;
        self::$refuseBytes      = false;
        self::$allowedTaskCalls = 0;
        self::$calls            = [];
    }
}
