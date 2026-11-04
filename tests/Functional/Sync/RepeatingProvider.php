<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Tests\Functional\Sync;

use DateTimeImmutable;
use Nowo\TaskBoardBundle\Sync\Exception\ProviderErrorCode;
use Nowo\TaskBoardBundle\Sync\Exception\ProviderException;
use Nowo\TaskBoardBundle\Sync\Exception\RateLimitedException;
use Nowo\TaskBoardBundle\Sync\Provider\ContainerPage;
use Nowo\TaskBoardBundle\Sync\Provider\ProviderCapabilities;
use Nowo\TaskBoardBundle\Sync\Provider\ProviderCredential;
use Nowo\TaskBoardBundle\Sync\Provider\RemoteContainer;
use Nowo\TaskBoardBundle\Sync\Provider\RemoteTaskPage;
use Nowo\TaskBoardBundle\Sync\Provider\TaskProviderInterface;
use Nowo\TaskBoardBundle\Sync\Testing\InMemoryTaskProvider;

/**
 * The in-memory provider, with task pages that hold every row twice (a provider that repeats rows across pages) or
 * comments or an id scan that fail.
 */
final readonly class RepeatingProvider implements TaskProviderInterface
{
    public function __construct(private InMemoryTaskProvider $inner, private bool $repeat, private bool $failComments = false, private bool $failIds = false, private bool $rateLimited = false)
    {
    }

    public function id(): string
    {
        return $this->inner->id();
    }

    public function labelKey(): string
    {
        return $this->inner->labelKey();
    }

    public function capabilities(): ProviderCapabilities
    {
        return $this->inner->capabilities();
    }

    public function importScope(ProviderCredential $credential): string
    {
        return $this->inner->importScope($credential);
    }

    public function listContainers(ProviderCredential $credential, ?string $parentId = null, ?string $cursor = null): ContainerPage
    {
        return $this->inner->listContainers($credential, $parentId, $cursor);
    }

    public function findContainer(ProviderCredential $credential, string $containerId): ?RemoteContainer
    {
        return $this->inner->findContainer($credential, $containerId);
    }

    public function fetchTasks(ProviderCredential $credential, string $listId, ?DateTimeImmutable $updatedSince, ?string $cursor): RemoteTaskPage
    {
        $page = $this->inner->fetchTasks($credential, $listId, $updatedSince, $cursor);

        return $this->repeat ? new RemoteTaskPage([...$page->tasks, ...$page->tasks], $page->nextCursor, $page->lastPage) : $page;
    }

    public function fetchComments(ProviderCredential $credential, string $taskId): iterable
    {
        if ($this->failComments) {
            throw new ProviderException(ProviderErrorCode::ServerError);
        }

        return $this->inner->fetchComments($credential, $taskId);
    }

    public function existingTaskIds(ProviderCredential $credential, string $listId): iterable
    {
        if ($this->failIds) {
            throw $this->rateLimited ? new RateLimitedException(30) : new ProviderException(ProviderErrorCode::ServerError);
        }

        return $this->inner->existingTaskIds($credential, $listId);
    }
}
