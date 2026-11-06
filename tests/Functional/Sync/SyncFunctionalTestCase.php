<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Tests\Functional\Sync;

use DateTimeImmutable;
use Nowo\TaskBoardBundle\Entity\Task;
use Nowo\TaskBoardBundle\Entity\TaskBoard;
use Nowo\TaskBoardBundle\Enum\TaskDependencyType;
use Nowo\TaskBoardBundle\Enum\TaskPriority;
use Nowo\TaskBoardBundle\Import\Dto\ImportedTaskDto;
use Nowo\TaskBoardBundle\Sync\Dto\SyncOptions;
use Nowo\TaskBoardBundle\Sync\Dto\SyncReport;
use Nowo\TaskBoardBundle\Sync\Entity\TaskBoardConnection;
use Nowo\TaskBoardBundle\Sync\Provider\ContainerType;
use Nowo\TaskBoardBundle\Sync\Provider\ProviderCredential;
use Nowo\TaskBoardBundle\Sync\Provider\RemoteAttachment;
use Nowo\TaskBoardBundle\Sync\Provider\RemoteContainer;
use Nowo\TaskBoardBundle\Sync\Provider\RemoteTask;
use Nowo\TaskBoardBundle\Sync\Provider\TaskProviderInterface;
use Nowo\TaskBoardBundle\Sync\TaskBoardSyncService;
use Nowo\TaskBoardBundle\Sync\Testing\InMemoryTaskProvider;
use Nowo\TaskBoardBundle\Tests\Functional\App\Entity\AppUser;
use Nowo\TaskBoardBundle\Tests\Functional\App\FixtureHttpClient;
use Nowo\TaskBoardBundle\Tests\Functional\App\RecordingAttachmentStorage;
use Nowo\TaskBoardBundle\Tests\Functional\App\TestCredentialResolver;
use Nowo\TaskBoardBundle\Tests\Functional\App\TestSyncLimitChecker;
use Nowo\TaskBoardBundle\Tests\Functional\FunctionalTestCase;

/**
 * Since 1.23: the functional kernel with `sync.enabled`, the in-memory provider as "clickup", the recording storage
 * and quotas, and no network.
 */
abstract class SyncFunctionalTestCase extends FunctionalTestCase
{
    public const LIST = 'L1';

    public const SYNC_CONFIG = ['sync' => [
        'enabled'             => true,
        'credential_resolver' => 'app.sync_credentials',
        'attachment_storage'  => 'app.sync_storage',
        'limit_checker'       => 'app.sync_limits',
        'container_cache_ttl' => 0,
        'providers'           => ['clickup' => ['enabled' => false]],
        // Since 1.27.0: the 1.23 tests read the "Fields" document; the typed fields are written too.
        'custom_fields' => ['mode' => 'both'],
    ]];

    protected InMemoryTaskProvider $provider;

    protected function setUp(): void
    {
        static::$taskBoardConfig = static::syncConfig();
        TestCredentialResolver::reset();
        RecordingAttachmentStorage::reset();
        TestSyncLimitChecker::reset();
        FixtureHttpClient::resetFixtures();
        parent::setUp();
        $provider = static::getContainer()->get('app.memory_provider');
        self::assertInstanceOf(InMemoryTaskProvider::class, $provider);
        $this->provider = $provider;
        $this->provider->addContainer(new RemoteContainer('w:1', 'Workspace 1', ContainerType::Workspace, null, false, true));
        $this->provider->addContainer(new RemoteContainer('s:1', 'Space 1', ContainerType::Space, 'w:1', false, true));
        $this->provider->addContainer(new RemoteContainer(self::LIST, 'Backlog', ContainerType::List, 's:1', true, false, 3, ['Space 1']));
    }

    /**
     * @return array<string, mixed>
     */
    protected static function syncConfig(): array
    {
        return self::SYNC_CONFIG;
    }

    protected function service(): TaskBoardSyncService
    {
        $service = static::getContainer()->get(TaskBoardSyncService::class);
        self::assertInstanceOf(TaskBoardSyncService::class, $service);

        return $service;
    }

    /**
     * @param list<array{0: string, 1: TaskDependencyType}> $dependsOn
     * @param list<RemoteAttachment> $attachments
     * @param list<string> $images
     */
    protected function remote(
        string $id,
        string $title,
        int $updated = 1,
        ?string $status = 'To do',
        ?string $description = null,
        ?string $parent = null,
        array $dependsOn = [],
        array $attachments = [],
        array $images = [],
        ?string $fields = null,
        bool $archived = false,
        ?string $email = null,
        ?DateTimeImmutable $due = null,
        array $tags = [],
    ): RemoteTask {
        return new RemoteTask(
            task: new ImportedTaskDto(
                title: $title,
                externalId: $id,
                parentExternalId: $parent,
                status: $status,
                priority: TaskPriority::Normal,
                description: $description,
                assigneeEmail: $email,
                dueAt: $due,
                tags: $tags,
                sourceUrl: 'https://app.clickup.com/t/' . $id,
                dependsOn: $dependsOn,
                hasPriority: false,
            ),
            remoteUpdatedAt: new DateTimeImmutable('@' . (1_790_000_000 + $updated)),
            archived: $archived,
            attachments: $attachments,
            inlineImageUrls: $images,
            customFieldsText: $fields,
        );
    }

    protected function put(RemoteTask ...$tasks): void
    {
        foreach ($tasks as $task) {
            $this->provider->putTask(self::LIST, $task);
        }
    }

    protected function sync(TaskBoard $board, ?TaskProviderInterface $provider = null, ?ProviderCredential $credential = null, mixed ...$options): SyncReport
    {
        $board = $this->em->find(TaskBoard::class, $board->getId());
        self::assertInstanceOf(TaskBoard::class, $board);

        $report = $this->service()->run(
            $board,
            $provider ?? $this->provider,
            $credential ?? new ProviderCredential(TestCredentialResolver::$secret, 'conn-1'),
            self::LIST,
            new SyncOptions(...['actor' => $this->owner, ...$options]),
        );
        $this->refreshOwner();

        return $report;
    }

    /** A sync clears the entity manager: the test's users are loaded again. */
    protected function refreshOwner(): void
    {
        $owner = $this->em->find(AppUser::class, $this->owner->getId());
        self::assertInstanceOf(AppUser::class, $owner);
        $this->owner = $owner;
    }

    protected function freshBoard(TaskBoard $board): TaskBoard
    {
        $this->em->clear();
        $this->refreshOwner();
        $fresh = $this->em->find(TaskBoard::class, $board->getId());
        self::assertInstanceOf(TaskBoard::class, $fresh);

        return $fresh;
    }

    /**
     * @return list<Task>
     */
    protected function tasksOf(TaskBoard $board): array
    {
        $this->em->clear();
        $this->refreshOwner();

        /** @var list<Task> $tasks */
        $tasks = $this->em->getRepository(Task::class)->findBy(['board' => $board->getId()], ['position' => 'ASC']);

        return $tasks;
    }

    protected function taskTitled(string $title): Task
    {
        $task = $this->em->getRepository(Task::class)->findOneBy(['title' => $title]);
        self::assertInstanceOf(Task::class, $task, $title);

        return $task;
    }

    protected function connectionOf(TaskBoard $board): ?TaskBoardConnection
    {
        return $this->em->getRepository(TaskBoardConnection::class)->findOneBy(['board' => $board->getId()]);
    }
}
