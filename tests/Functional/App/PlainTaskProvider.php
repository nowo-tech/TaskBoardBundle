<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Tests\Functional\App;

use DateTimeImmutable;
use Nowo\TaskBoardBundle\Sync\Provider\ContainerPage;
use Nowo\TaskBoardBundle\Sync\Provider\ProviderCapabilities;
use Nowo\TaskBoardBundle\Sync\Provider\ProviderCredential;
use Nowo\TaskBoardBundle\Sync\Provider\RemoteContainer;
use Nowo\TaskBoardBundle\Sync\Provider\RemoteTaskPage;
use Nowo\TaskBoardBundle\Sync\Provider\TaskProviderInterface;
use Nowo\TaskBoardBundle\Sync\Testing\InMemoryTaskProvider;

/**
 * Since 3.2.0: a provider with the base interface only (no sources, statuses, fields or people), as "plain", reading
 * the in-memory provider's data (used when a test sets `__plain_provider`).
 */
final readonly class PlainTaskProvider implements TaskProviderInterface
{
    public function __construct(private InMemoryTaskProvider $inner)
    {
    }

    public function id(): string
    {
        return 'plain';
    }

    public function labelKey(): string
    {
        return 'task_board.sync.provider.clickup';
    }

    public function capabilities(): ProviderCapabilities
    {
        return new ProviderCapabilities(true, false, false, false, true, true);
    }

    public function importScope(ProviderCredential $credential): string
    {
        return 'plain';
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
        return [];
    }

    public function existingTaskIds(ProviderCredential $credential, string $listId): iterable
    {
        return $this->inner->existingTaskIds($credential, $listId);
    }
}
