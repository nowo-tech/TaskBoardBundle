<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Tests\Functional\App;

use DateTimeImmutable;
use Nowo\TaskBoardBundle\Service\TaskTimeBillableDefaultProviderInterface;
use Nowo\TaskBoardBundle\Service\TaskTimePeriodLockInterface;

use function in_array;

/** Since 2.12.0: the host's billable default and period lock of the functional kernels (none until a test sets them). */
final class ScriptedTimeBillable implements TaskTimeBillableDefaultProviderInterface, TaskTimePeriodLockInterface
{
    public static ?bool $default = null;

    /** @var list<string> days (`Y-m-d`) whose period is locked */
    public static array $lockedDays = [];

    public static function reset(): void
    {
        self::$default    = null;
        self::$lockedDays = [];
    }

    public function defaultFor(object $user, string $taskId, ?string $boardId): ?bool
    {
        return self::$default;
    }

    public function isLocked(object $user, string $taskId, ?string $boardId, DateTimeImmutable $loggedAt): bool
    {
        return in_array($loggedAt->format('Y-m-d'), self::$lockedDays, true);
    }
}
