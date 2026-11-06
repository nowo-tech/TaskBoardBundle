<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Sync\Testing;

use DateTimeImmutable;
use Nowo\TaskBoardBundle\Sync\Exception\ProviderErrorCode;
use Nowo\TaskBoardBundle\Sync\Exception\ProviderException;
use Nowo\TaskBoardBundle\Sync\Provider\ContainerPage;
use Nowo\TaskBoardBundle\Sync\Provider\FieldCoverageProviderInterface;
use Nowo\TaskBoardBundle\Sync\Provider\PeopleProviderInterface;
use Nowo\TaskBoardBundle\Sync\Provider\ProviderApiGapsInterface;
use Nowo\TaskBoardBundle\Sync\Provider\ProviderCapabilities;
use Nowo\TaskBoardBundle\Sync\Provider\ProviderCredential;
use Nowo\TaskBoardBundle\Sync\Provider\RemoteComment;
use Nowo\TaskBoardBundle\Sync\Provider\RemoteContainer;
use Nowo\TaskBoardBundle\Sync\Provider\RemoteLocation;
use Nowo\TaskBoardBundle\Sync\Provider\RemoteLocationKind;
use Nowo\TaskBoardBundle\Sync\Provider\RemotePerson;
use Nowo\TaskBoardBundle\Sync\Provider\RemotePersonPage;
use Nowo\TaskBoardBundle\Sync\Provider\RemoteSource;
use Nowo\TaskBoardBundle\Sync\Provider\RemoteStatus;
use Nowo\TaskBoardBundle\Sync\Provider\RemoteTask;
use Nowo\TaskBoardBundle\Sync\Provider\RemoteTaskPage;
use Nowo\TaskBoardBundle\Sync\Provider\RemoteTimeEntry;
use Nowo\TaskBoardBundle\Sync\Provider\SourceProviderInterface;
use Nowo\TaskBoardBundle\Sync\Provider\TaskProviderInterface;
use Nowo\TaskBoardBundle\Sync\Provider\TaskTypeProviderInterface;
use Nowo\TaskBoardBundle\Sync\Provider\TimeAccess;
use Nowo\TaskBoardBundle\Sync\Provider\TimeAccessMode;
use Nowo\TaskBoardBundle\Sync\Provider\TimeEntryProviderInterface;
use Nowo\TaskBoardBundle\Sync\Provider\TimeEntryQuery;

use function array_filter;
use function array_key_exists;
use function array_merge;
use function array_shift;
use function array_slice;
use function array_values;
use function count;
use function ctype_digit;
use function in_array;
use function mb_strtolower;
use function preg_match;
use function str_contains;
use function usort;

/**
 * Since 1.23: a scriptable provider for tests and demos (Beacon's e2e registers it as "clickup" in `when@test`) —
 * a tree of containers, tasks per list, comments per task, and failures to inject. It never opens a connection.
 *
 * Only a credential whose secret is {@see self::$acceptedSecret} is accepted (others: auth_failed).
 *
 * Since 1.25 it also scripts the people of a workspace ({@see self::withWorkspace()}), tracked time
 * ({@see self::withTimeEntries()}, {@see self::withTaskIntervals()}), folder / space sources ({@see self::addSource()})
 * and statuses ({@see self::withStatuses()}). The `people` / `timeEntries` capabilities are on only once a workspace
 * or time entries are set, so a 1.23 script keeps 1.23 behaviour.
 *
 * @final
 */
class InMemoryTaskProvider implements TaskProviderInterface, PeopleProviderInterface, TimeEntryProviderInterface, SourceProviderInterface, ProviderApiGapsInterface, TaskTypeProviderInterface, FieldCoverageProviderInterface
{
    /** Since 1.25: the workspace every container belongs to (null = no people). */
    private ?string $workspaceId = null;

    /** @var list<RemotePerson> since 1.25 */
    private array $people = [];

    private TimeAccess $timeAccess;

    /** @var list<RemoteTimeEntry>|null since 1.25 (null = no time capability) */
    private ?array $timeEntries = null;

    /** @var array<string, list<RemoteTimeEntry>|null> since 1.25: task id => legacy intervals (null = gone) */
    private array $intervals = [];

    /** @var array<string, array{0: RemoteSource, 1: list<string>}> since 1.25: container id => source, its list ids */
    private array $sources = [];

    /** @var array<string, list<RemoteStatus>> since 1.25: container id => statuses */
    private array $statuses = [];

    /** @var array<string, list<RemoteContainer>> parent id ('' = top) => children */
    private array $containers = [];

    /** @var array<string, array<string, RemoteTask>> list id => external id => task */
    private array $tasks = [];

    /** @var array<string, list<RemoteComment>> */
    private array $comments = [];

    /** @var list<ProviderException|null> failures for the next calls, in order (null = answer normally) */
    private array $failures = [];

    /** @var array<string, string> since 1.27.0: the declared coverage of task answer keys */
    private array $coverage = [];

    /** @var list<string> the calls made, as "method:argument" (no credential) */
    public array $calls = [];

    /** @var array<string, string> since 1.28.0 */
    private array $apiGaps = [];

    /** @var array<string, string> since 1.28.0 */
    private array $taskTypes = [];

    /**
     * @param list<string> $attachmentHosts
     */
    public function __construct(
        private readonly string $providerId = 'clickup',
        private readonly string $acceptedSecret = 'pk_test_token',
        private readonly int $pageSize = 100,
        private readonly array $attachmentHosts = ['*.clickup-attachments.com'],
    ) {
        $this->timeAccess = new TimeAccess(TimeAccessMode::Admin);
    }

    /**
     * Since 1.25: every container belongs to `$workspaceId`, whose members are `$people`.
     *
     * @param list<RemotePerson> $people
     */
    public function withWorkspace(string $workspaceId, array $people = [], ?TimeAccess $timeAccess = null): self
    {
        $this->workspaceId = $workspaceId;
        $this->people      = $people;
        $this->timeAccess  = $timeAccess ?? $this->timeAccess;

        return $this;
    }

    /**
     * Since 1.25: the tracked time of the workspace (filtered by window, task location ignored, and assignees).
     *
     * @param list<RemoteTimeEntry> $entries
     */
    public function withTimeEntries(array $entries): self
    {
        $this->timeEntries = $entries;

        return $this;
    }

    /**
     * Since 1.25: the legacy intervals of a task (null = the provider no longer serves them).
     *
     * @param list<RemoteTimeEntry>|null $entries
     */
    public function withTaskIntervals(string $taskId, ?array $entries): self
    {
        $this->intervals[$taskId] = $entries;

        return $this;
    }

    /**
     * Since 1.25: a folder or space source made of lists (their tasks come from {@see self::putTask()}).
     *
     * @param list<string> $listIds
     */
    public function addSource(RemoteSource $source, array $listIds): self
    {
        $this->sources[$source->location->containerId()] = [$source, $listIds];

        return $this;
    }

    /**
     * Since 1.25: the statuses of a container ("<list id>", "f:<id>" or "s:<id>").
     *
     * @param list<RemoteStatus> $statuses
     */
    public function withStatuses(string $containerId, array $statuses): self
    {
        $this->statuses[$containerId] = $statuses;

        return $this;
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

    /**
     * Since 1.28.0: what the provider's API does not give (completeness report).
     *
     * @param array<string, string> $gaps name => reason
     */
    public function withApiGaps(array $gaps): self
    {
        $this->apiGaps = $gaps;

        return $this;
    }

    /**
     * Since 1.28.0.
     *
     * @param array<string, string> $types id => name
     */
    public function withTaskTypes(array $types): self
    {
        $this->taskTypes = $types;

        return $this;
    }

    public function apiGaps(): array
    {
        return $this->apiGaps;
    }

    public function fetchTaskTypes(ProviderCredential $credential, string $workspaceId): array
    {
        $this->enter($credential, 'fetchTaskTypes:' . $workspaceId);

        return $this->taskTypes;
    }

    /**
     * Since 1.27.0: what the provider declares about its answer keys (completeness report).
     *
     * @param array<string, string> $coverage key => `imported` or a reason
     */
    public function withFieldCoverage(array $coverage): self
    {
        $this->coverage = $coverage;

        return $this;
    }

    public function fieldCoverage(): array
    {
        return $this->coverage;
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
        return new ProviderCapabilities(true, true, true, true, true, true, $this->attachmentHosts, $this->workspaceId !== null, $this->timeEntries !== null, true, true);
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

    public function workspaceOf(ProviderCredential $credential, string $containerId): ?string
    {
        $this->enter($credential, 'workspaceOf:' . $containerId);

        return $this->workspaceId;
    }

    public function fetchPeople(ProviderCredential $credential, string $workspaceId, ?string $cursor = null): RemotePersonPage
    {
        $this->enter($credential, 'fetchPeople:' . $workspaceId);
        if ($workspaceId !== $this->workspaceId) {
            return new RemotePersonPage([]);
        }
        $offset = $cursor !== null && ctype_digit($cursor) ? (int) $cursor : 0;

        return new RemotePersonPage(array_slice($this->people, $offset, $this->pageSize), $offset + $this->pageSize < count($this->people) ? (string) ($offset + $this->pageSize) : null);
    }

    public function timeAccess(ProviderCredential $credential, string $workspaceId): TimeAccess
    {
        $this->enter($credential, 'timeAccess:' . $workspaceId);

        return $this->timeAccess;
    }

    public function fetchTimeEntries(ProviderCredential $credential, TimeEntryQuery $query): iterable
    {
        $this->enter($credential, 'fetchTimeEntries:' . $query->from->getTimestamp() . '-' . $query->to->getTimestamp());
        $own = $this->timeAccess->tokenUserId;

        return array_values(array_filter($this->timeEntries ?? [], static fn (RemoteTimeEntry $e): bool => $e->start >= $query->from && $e->start < $query->to
            && ($query->assigneeIds === null ? $own === null || $e->person->providerUserId === $own : in_array($e->person->providerUserId, $query->assigneeIds, true))));
    }

    public function fetchTaskTimeIntervals(ProviderCredential $credential, string $taskId): ?iterable
    {
        $this->enter($credential, 'fetchTaskTimeIntervals:' . $taskId);

        return array_key_exists($taskId, $this->intervals) ? $this->intervals[$taskId] : [];
    }

    public function findSource(ProviderCredential $credential, string $containerId): ?RemoteSource
    {
        $this->enter($credential, 'findSource:' . $containerId);

        $source = ($this->sources[$containerId] ?? null)[0] ?? null;
        if ($source !== null) {
            return $source;
        }
        // Since 1.28.0: a list source, from its container (description included).
        $location = RemoteLocation::fromContainerId($containerId);
        foreach ($this->containers as $level) {
            foreach ($level as $container) {
                if ($container->id === $containerId && $location instanceof RemoteLocation) {
                    return new RemoteSource($location, $container->name, null, $container->path, 1, $container->taskCount, $container->description);
                }
            }
        }

        return null;
    }

    public function fetchSourceTasks(ProviderCredential $credential, RemoteSource $source, ?DateTimeImmutable $updatedSince, ?string $cursor): RemoteTaskPage
    {
        $this->enter($credential, 'fetchSourceTasks:' . $source->location->containerId());
        $tasks = [];
        foreach ($this->listIdsOf($source) as $listId) {
            $tasks = array_merge($tasks, array_values($this->tasks[$listId] ?? []));
        }
        $tasks = array_values(array_filter($tasks, static fn (RemoteTask $t): bool => !$updatedSince instanceof DateTimeImmutable || $t->remoteUpdatedAt > $updatedSince));
        usort($tasks, static fn (RemoteTask $a, RemoteTask $b): int => $a->remoteUpdatedAt <=> $b->remoteUpdatedAt);
        $offset = $cursor !== null && ctype_digit($cursor) ? (int) $cursor : 0;
        $last   = $offset + $this->pageSize >= count($tasks);

        return new RemoteTaskPage(array_slice($tasks, $offset, $this->pageSize), $last ? null : (string) ($offset + $this->pageSize), $last);
    }

    public function existingSourceTaskIds(ProviderCredential $credential, RemoteSource $source): iterable
    {
        $this->enter($credential, 'existingSourceTaskIds:' . $source->location->containerId());
        foreach ($this->listIdsOf($source) as $listId) {
            foreach ($this->tasks[$listId] ?? [] as $id => $task) {
                if (!$task->archived) {
                    yield (string) $id;
                }
            }
        }
    }

    public function listStatuses(ProviderCredential $credential, RemoteSource $source): array
    {
        $this->enter($credential, 'listStatuses:' . $source->location->containerId());

        return $this->statuses[$source->location->containerId()] ?? [];
    }

    /** Since 1.25: "…/v/li/<id>" of any https address (the ClickUp adapter is stricter). */
    public function resolveListFromUrl(string $url): ?string
    {
        return preg_match('#^https://[^/]+/(?:\d+/)?v/li/([A-Za-z0-9_-]{1,64})/?$#D', $url, $match) === 1 ? $match[1] : null;
    }

    public function searchLists(ProviderCredential $credential, string $query, int $limit = 20): array
    {
        $this->enter($credential, 'searchLists');
        $found = [];
        foreach ($this->containers as $children) {
            foreach ($children as $container) {
                if ($container->selectable && count($found) < $limit && str_contains(mb_strtolower($container->name), mb_strtolower($query))) {
                    $found[] = $container;
                }
            }
        }

        return $found;
    }

    /**
     * @return list<string>
     */
    private function listIdsOf(RemoteSource $source): array
    {
        return $source->location->kind === RemoteLocationKind::List ? [$source->location->id] : ($this->sources[$source->location->containerId()][1] ?? []);
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
