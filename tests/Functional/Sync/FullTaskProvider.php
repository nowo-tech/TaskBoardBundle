<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Tests\Functional\Sync;

use Nowo\TaskBoardBundle\Sync\Provider\ProviderCredential;
use Nowo\TaskBoardBundle\Sync\Provider\RemoteStatusTime;
use Nowo\TaskBoardBundle\Sync\Provider\StatusHistoryProviderInterface;
use Nowo\TaskBoardBundle\Sync\Testing\InMemoryTaskProvider;

/**
 * The in-memory provider with a scripted "time in status" (null = not served for that task).
 */
final class FullTaskProvider extends InMemoryTaskProvider implements StatusHistoryProviderInterface
{
    /** @var array<string, list<RemoteStatusTime>|null> */
    public array $history = [];

    public function fetchStatusHistory(ProviderCredential $credential, string $taskId): ?array
    {
        return $this->history[$taskId] ?? null;
    }
}
