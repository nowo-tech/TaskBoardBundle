<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Tests\Functional\Sync\People;

use DateTimeImmutable;
use Nowo\TaskBoardBundle\Sync\Provider\ContainerPage;
use Nowo\TaskBoardBundle\Sync\Provider\ProviderCapabilities;
use Nowo\TaskBoardBundle\Sync\Provider\ProviderCredential;
use Nowo\TaskBoardBundle\Sync\Provider\RemoteContainer;
use Nowo\TaskBoardBundle\Sync\Provider\RemoteTaskPage;
use Nowo\TaskBoardBundle\Sync\Provider\TaskProviderInterface;
use Nowo\TaskBoardBundle\Sync\Testing\InMemoryTaskProvider;

/**
 * The in-memory provider behind the 1.23 interface only (no people, sources or tracked time).
 */
class PlainTaskProvider implements TaskProviderInterface
{
    public function __construct(
        public readonly InMemoryTaskProvider $inner = new InMemoryTaskProvider(),
    ) {
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
        return $this->inner->fetchTasks($credential, $listId, $updatedSince, $cursor);
    }

    public function fetchComments(ProviderCredential $credential, string $taskId): iterable
    {
        return $this->inner->fetchComments($credential, $taskId);
    }

    public function existingTaskIds(ProviderCredential $credential, string $listId): iterable
    {
        return $this->inner->existingTaskIds($credential, $listId);
    }
}
