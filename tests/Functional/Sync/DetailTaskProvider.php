<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Tests\Functional\Sync;

use Nowo\TaskBoardBundle\Sync\Provider\ProviderCredential;
use Nowo\TaskBoardBundle\Sync\Provider\RemoteTask;
use Nowo\TaskBoardBundle\Sync\Provider\TaskDetailProviderInterface;
use Nowo\TaskBoardBundle\Sync\Testing\InMemoryTaskProvider;

/**
 * Since 1.25: the in-memory provider with the E5 task detail (rows whose list answer leaves out watchers or
 * checklists); every detail call is recorded in `$calls`.
 */
final class DetailTaskProvider extends InMemoryTaskProvider implements TaskDetailProviderInterface
{
    /** @var array<string, RemoteTask> */
    private array $details = [];

    public function withTaskDetail(RemoteTask $task): self
    {
        $this->details[$task->externalId()] = $task;

        return $this;
    }

    public function fetchTaskDetail(ProviderCredential $credential, string $taskId): ?RemoteTask
    {
        $this->calls[] = 'fetchTaskDetail:' . $taskId;

        return $this->details[$taskId] ?? null;
    }
}
