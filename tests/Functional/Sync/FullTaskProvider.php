<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Tests\Functional\Sync;

use DateTimeImmutable;
use Nowo\TaskBoardBundle\Sync\Exception\ProviderException;
use Nowo\TaskBoardBundle\Sync\Provider\ProviderCredential;
use Nowo\TaskBoardBundle\Sync\Provider\RemoteStatusTime;
use Nowo\TaskBoardBundle\Sync\Provider\RemoteTaskPage;
use Nowo\TaskBoardBundle\Sync\Provider\StatusHistoryProviderInterface;
use Nowo\TaskBoardBundle\Sync\Testing\InMemoryTaskProvider;

/**
 * The in-memory provider with a scripted "time in status" (null = not served for that task) and, when set, scripted
 * task pages per cursor ('' = the first page) that ignore the updated-since filter.
 */
final class FullTaskProvider extends InMemoryTaskProvider implements StatusHistoryProviderInterface
{
    /** @var array<string, list<RemoteStatusTime>|null> */
    public array $history = [];

    public ?ProviderException $historyFailure = null;

    /** @var array<string, RemoteTaskPage> */
    public array $pages = [];

    public function fetchTasks(ProviderCredential $credential, string $listId, ?DateTimeImmutable $updatedSince, ?string $cursor): RemoteTaskPage
    {
        return $this->pages[$cursor ?? ''] ?? parent::fetchTasks($credential, $listId, $updatedSince, $cursor);
    }

    public function fetchStatusHistory(ProviderCredential $credential, string $taskId): ?array
    {
        if ($this->historyFailure instanceof ProviderException) {
            throw $this->historyFailure;
        }

        return $this->history[$taskId] ?? null;
    }
}
