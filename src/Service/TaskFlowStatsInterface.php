<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Service;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use Nowo\TaskBoardBundle\View\TaskColumnEntry;
use Nowo\TaskBoardBundle\View\TaskCompletionSample;
use Nowo\TaskBoardBundle\View\TaskFlowDay;

/**
 * Since 2.29.0 (DDD-host checklist B7): flow statistics over the tasks of some tenants for a host's analytics, so it
 * never reads `TaskChangeHistory` or the task rows itself. Implemented by {@see TaskFlowStats}. A system read: no access
 * is checked. Tenant keys are matched byte for byte (at most {@see self::MAX_KEYS}); windows are half-open
 * (`from` included, `to` excluded) and at most {@see self::MAX_DAYS} days long.
 */
interface TaskFlowStatsInterface
{
    public const MAX_KEYS = 500;

    public const MAX_DAYS = 400;

    /** Most samples or entries per call. */
    public const MAX_SAMPLES = 5000;

    /**
     * Tasks created and completed per tenant and day in the window (archived tasks included), days in the given time
     * zone (default: UTC). Only days with at least one of either are returned, ordered by tenant key then day.
     *
     * @param list<string> $tenantKeys
     *
     * @throws InvalidArgumentException on too many keys or an invalid window
     *
     * @return list<TaskFlowDay>
     */
    public function dailyFlow(array $tenantKeys, DateTimeImmutable $from, DateTimeImmutable $to, ?DateTimeZone $timeZone = null): array;

    /**
     * Tasks completed in the window with their creation and completion dates (lead time), oldest completion first.
     *
     * @param list<string> $tenantKeys
     *
     * @throws InvalidArgumentException on too many keys, an invalid window or a limit out of 1..MAX_SAMPLES
     *
     * @return list<TaskCompletionSample>
     */
    public function completedSamples(array $tenantKeys, DateTimeImmutable $from, DateTimeImmutable $to, int $limit = 1000): array;

    /**
     * The first time each task entered a column named `$columnName` (from the task history), when that first time is in
     * the window; earliest first. The name is compared byte for byte (since 2.29.0 audit YJ-L5; the database may fold it).
     *
     * @param list<string> $tenantKeys
     *
     * @throws InvalidArgumentException on too many keys, an invalid window or a limit out of 1..MAX_SAMPLES
     *
     * @return list<TaskColumnEntry>
     */
    public function firstEntries(array $tenantKeys, string $columnName, DateTimeImmutable $from, DateTimeImmutable $to, int $limit = 1000): array;
}
