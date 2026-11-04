<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Sync\Testing;

use DateTimeImmutable;
use Nowo\TaskBoardBundle\Sync\Exception\ProviderErrorCode;
use Nowo\TaskBoardBundle\Sync\Exception\ProviderException;
use Nowo\TaskBoardBundle\Sync\Provider\ContainerPage;
use Nowo\TaskBoardBundle\Sync\Provider\ProviderCapabilities;
use Nowo\TaskBoardBundle\Sync\Provider\ProviderCredential;
use Nowo\TaskBoardBundle\Sync\Provider\RemoteComment;
use Nowo\TaskBoardBundle\Sync\Provider\RemoteContainer;
use Nowo\TaskBoardBundle\Sync\Provider\RemoteTask;
use Nowo\TaskBoardBundle\Sync\Provider\RemoteTaskPage;
use Nowo\TaskBoardBundle\Sync\Provider\TaskProviderInterface;

use function array_filter;
use function array_shift;
use function array_slice;
use function array_values;
use function count;
use function ctype_digit;
use function usort;

/**
 * Since 1.23: a scriptable provider for tests and demos (Beacon's e2e registers it as "clickup" in `when@test`) —
 * a tree of containers, tasks per list, comments per task, and failures to inject. It never opens a connection.
 *
 * Only a credential whose secret is {@see self::$acceptedSecret} is accepted (others: auth_failed).
 *
 * @final
 */
class InMemoryTaskProvider implements TaskProviderInterface
{
    /** @var array<string, list<RemoteContainer>> parent id ('' = top) => children */
    private array $containers = [];

    /** @var array<string, array<string, RemoteTask>> list id => external id => task */
    private array $tasks = [];

    /** @var array<string, list<RemoteComment>> */
    private array $comments = [];

    /** @var list<ProviderException|null> failures for the next calls, in order (null = answer normally) */
    private array $failures = [];

    /** @var list<string> the calls made, as "method:argument" (no credential) */
    public array $calls = [];

    /**
     * @param list<string> $attachmentHosts
     */
    public function __construct(
        private readonly string $providerId = 'clickup',
        private readonly string $acceptedSecret = 'pk_test_token',
        private readonly int $pageSize = 100,
        private readonly array $attachmentHosts = ['*.clickup-attachments.com'],
    ) {
    }

    public function addContainer(RemoteContainer $container): self
    {
        $this->containers[$container->parentId ?? ''][] = $container;

        return $this;
    }

    public function putTask(string $listId, RemoteTask $task): self
    {
        $this->tasks[$listId][$task->externalId()] = $task;

        return $this;
    }

    public function removeTask(string $listId, string $externalId): self
    {
        unset($this->tasks[$listId][$externalId]);

        return $this;
    }

    /**
     * @param list<RemoteComment> $comments
     */
    public function setComments(string $taskId, array $comments): self
    {
        $this->comments[$taskId] = $comments;

        return $this;
    }

    /** The next call fails with `$failure` (queue several; null lets a call through). */
    public function failNext(?ProviderException $failure): self
    {
        $this->failures[] = $failure;

        return $this;
    }

    public function id(): string
    {
        return $this->providerId;
    }

    public function labelKey(): string
    {
        return 'task_board.sync.provider.' . $this->providerId;
    }

    public function capabilities(): ProviderCapabilities
    {
        return new ProviderCapabilities(true, true, true, true, true, true, $this->attachmentHosts);
    }

    public function importScope(ProviderCredential $credential): string
    {
        return $this->providerId;
    }

    public function listContainers(ProviderCredential $credential, ?string $parentId = null, ?string $cursor = null): ContainerPage
    {
        $this->enter($credential, 'listContainers:' . ($parentId ?? ''));
        $all    = $this->containers[$parentId ?? ''] ?? [];
        $offset = $cursor !== null && ctype_digit($cursor) ? (int) $cursor : 0;
        $items  = array_slice($all, $offset, $this->pageSize);

        return new ContainerPage($items, $offset + $this->pageSize < count($all) ? (string) ($offset + $this->pageSize) : null);
    }

    public function findContainer(ProviderCredential $credential, string $containerId): ?RemoteContainer
    {
        $this->enter($credential, 'findContainer:' . $containerId);
        foreach ($this->containers as $children) {
            foreach ($children as $container) {
                if ($container->id === $containerId && $container->selectable) {
                    return $container;
                }
            }
        }

        return null;
    }

    public function fetchTasks(ProviderCredential $credential, string $listId, ?DateTimeImmutable $updatedSince, ?string $cursor): RemoteTaskPage
    {
        $this->enter($credential, 'fetchTasks:' . $listId);
        $tasks = array_values(array_filter($this->tasks[$listId] ?? [], static fn (RemoteTask $t): bool => !$updatedSince instanceof DateTimeImmutable || $t->remoteUpdatedAt > $updatedSince));
        usort($tasks, static fn (RemoteTask $a, RemoteTask $b): int => $a->remoteUpdatedAt <=> $b->remoteUpdatedAt);
        $offset = $cursor !== null && ctype_digit($cursor) ? (int) $cursor : 0;
        $page   = array_slice($tasks, $offset, $this->pageSize);
        $last   = $offset + $this->pageSize >= count($tasks);

        return new RemoteTaskPage($page, $last ? null : (string) ($offset + $this->pageSize), $last);
    }

    public function fetchComments(ProviderCredential $credential, string $taskId): iterable
    {
        $this->enter($credential, 'fetchComments:' . $taskId);

        return $this->comments[$taskId] ?? [];
    }

    public function existingTaskIds(ProviderCredential $credential, string $listId): iterable
    {
        $this->enter($credential, 'existingTaskIds:' . $listId);
        foreach ($this->tasks[$listId] ?? [] as $id => $task) {
            if (!$task->archived) {
                yield (string) $id;
            }
        }
    }

    private function enter(ProviderCredential $credential, string $call): void
    {
        $this->calls[] = $call;
        if ($this->failures !== []) {
            $failure = array_shift($this->failures);
            if ($failure !== null) {
                throw $failure;
            }
        }
        if ($credential->reveal() !== $this->acceptedSecret) {
            throw new ProviderException(ProviderErrorCode::AuthFailed);
        }
    }
}
