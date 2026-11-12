<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Service;

/**
 * Since 2.12.0: no default `billable` (the flag of a new manual entry stays unset, as before 2.12).
 */
final class NullTaskTimeBillableDefaultProvider implements TaskTimeBillableDefaultProviderInterface
{
    public function defaultFor(object $user, string $taskId, ?string $boardId): ?bool
    {
        return null;
    }
}
