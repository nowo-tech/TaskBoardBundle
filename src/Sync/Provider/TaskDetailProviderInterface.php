<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Sync\Provider;

/**
 * Since 1.25 (E5): one task with its detail fields, for providers whose list rows leave out watchers or checklists
 * (`RemoteTask::$watchersKnown` / `$checklistsKnown` false). The sync calls it only for tasks it created or changed
 * in the run, within `sync.watchers.max_detail_calls_per_run`.
 */
interface TaskDetailProviderInterface
{
    /** The task, or null when it is gone. */
    public function fetchTaskDetail(ProviderCredential $credential, string $taskId): ?RemoteTask;
}
