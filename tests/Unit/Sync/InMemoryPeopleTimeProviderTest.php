<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Tests\Unit\Sync;

use DateTimeImmutable;
use Nowo\TaskBoardBundle\Import\Dto\ImportedTaskDto;
use Nowo\TaskBoardBundle\Sync\Exception\ProviderErrorCode;
use Nowo\TaskBoardBundle\Sync\Exception\ProviderException;
use Nowo\TaskBoardBundle\Sync\Provider\ContainerType;
use Nowo\TaskBoardBundle\Sync\Provider\ProviderCredential;
use Nowo\TaskBoardBundle\Sync\Provider\RemoteContainer;
use Nowo\TaskBoardBundle\Sync\Provider\RemoteLocation;
use Nowo\TaskBoardBundle\Sync\Provider\RemoteLocationKind;
use Nowo\TaskBoardBundle\Sync\Provider\RemotePerson;
use Nowo\TaskBoardBundle\Sync\Provider\RemotePersonRef;
use Nowo\TaskBoardBundle\Sync\Provider\RemoteRole;
use Nowo\TaskBoardBundle\Sync\Provider\RemoteSource;
use Nowo\TaskBoardBundle\Sync\Provider\RemoteStatus;
use Nowo\TaskBoardBundle\Sync\Provider\RemoteStatusType;
use Nowo\TaskBoardBundle\Sync\Provider\RemoteTask;
use Nowo\TaskBoardBundle\Sync\Provider\RemoteTimeEntry;
use Nowo\TaskBoardBundle\Sync\Provider\TimeAccess;
use Nowo\TaskBoardBundle\Sync\Provider\TimeAccessMode;
use Nowo\TaskBoardBundle\Sync\Provider\TimeEntryQuery;
use Nowo\TaskBoardBundle\Sync\Testing\InMemoryTaskProvider;
use PHPUnit\Framework\TestCase;

use function array_map;
use function iterator_to_array;

/**
 * Since 1.25: the scriptable provider's people, time, source and status scripting, and the new value objects.
 */
final class InMemoryPeopleTimeProviderTest extends TestCase
{
    public function testCapabilitiesStay123UntilScripted(): void
    {
        $provider = new InMemoryTaskProvider();
        self::assertFalse($provider->capabilities()->people);
        self::assertFalse($provider->capabilities()->timeEntries);

        $provider->withWorkspace('W1')->withTimeEntries([]);
        self::assertTrue($provider->capabilities()->people);
        self::assertTrue($provider->capabilities()->timeEntries);
        self::assertTrue($provider->capabilities()->watchers);
        self::assertTrue($provider->capabilities()->checklists);
    }

    public function testPeopleAndTime(): void
    {
        $credential = new ProviderCredential('pk_test_token', 'c');
        $people     = [];
        for ($i = 1; $i <= 3; ++$i) {
            $people[] = new RemotePerson((string) $i, 'User ' . $i, 'user' . $i . '@example.test', RemoteRole::Member);
        }
        $provider = new InMemoryTaskProvider(pageSize: 2);
        self::assertNull($provider->workspaceOf($credential, 'L1'));
        $provider->withWorkspace('W1', $people);
        self::assertSame('W1', $provider->workspaceOf($credential, 'L1'));
        $first = $provider->fetchPeople($credential, 'W1');
        self::assertCount(2, $first->people);
        self::assertSame('2', $first->nextCursor);
        $second = $provider->fetchPeople($credential, 'W1', $first->nextCursor);
        self::assertSame(['3'], array_map(static fn (RemotePerson $p): string => $p->providerUserId, $second->people));
        self::assertNull($second->nextCursor);
        self::assertSame([], $provider->fetchPeople($credential, 'other')->people);
        self::assertSame(TimeAccessMode::Admin, $provider->timeAccess($credential, 'W1')->mode);

        $at      = new DateTimeImmutable('@1793000000');
        $entries = [
            new RemoteTimeEntry('e1', 'T1', $people[0]->ref(), $at, $at->modify('+1 hour'), 3600),
            new RemoteTimeEntry('e2', 'T1', $people[1]->ref(), $at, $at->modify('+1 hour'), 3600),
            new RemoteTimeEntry('e3', null, $people[0]->ref(), $at->modify('+40 days'), null, -1, true),
        ];
        $provider->withTimeEntries($entries);
        $window = new TimeEntryQuery('W1', RemoteLocation::list('L1'), $at, $at->modify('+30 days'), ['2']);
        self::assertSame(['e2'], array_map(static fn (RemoteTimeEntry $e): string => $e->id, iterator_to_array($provider->fetchTimeEntries($credential, $window), false)));
        $all = new TimeEntryQuery('W1', RemoteLocation::list('L1'), $at, $at->modify('+60 days'));
        self::assertCount(3, iterator_to_array($provider->fetchTimeEntries($credential, $all), false), 'admin token without assignees: everything');

        $provider->withWorkspace('W1', $people, new TimeAccess(TimeAccessMode::Member, '1'));
        self::assertSame(['e1', 'e3'], array_map(static fn (RemoteTimeEntry $e): string => $e->id, iterator_to_array($provider->fetchTimeEntries($credential, $all), false)), 'member token: its own entries');

        $provider->withTaskIntervals('T1', [$entries[0]])->withTaskIntervals('GONE', null);
        self::assertCount(1, iterator_to_array($provider->fetchTaskTimeIntervals($credential, 'T1') ?? [], false));
        self::assertNull($provider->fetchTaskTimeIntervals($credential, 'GONE'));
        self::assertSame([], $provider->fetchTaskTimeIntervals($credential, 'OTHER'));
        self::assertContains('fetchPeople:W1', $provider->calls);

        $this->expectException(ProviderException::class);
        $provider->timeAccess(new ProviderCredential('pk_wrong', 'c'), 'W1');
    }

    public function testSourcesStatusesAndLookups(): void
    {
        $credential = new ProviderCredential('pk_test_token', 'c');
        $provider   = new InMemoryTaskProvider(pageSize: 1);
        $space      = new RemoteSource(new RemoteLocation(RemoteLocationKind::Space, 'S1'), 'Space 1', 'W1', [], 2, 3);
        $list       = new RemoteSource(RemoteLocation::list('L1'), 'List 1');
        $provider->addSource($space, ['L1', 'L2'])
            ->putTask('L1', $this->task('a', 10))
            ->putTask('L2', $this->task('b', 20))
            ->putTask('L2', $this->task('c', 30, true))
            ->withStatuses('s:S1', [new RemoteStatus('done', RemoteStatusType::Done)])
            ->addContainer(new RemoteContainer('L1', 'Roadmap list', ContainerType::List, 's:S1', true, false))
            ->addContainer(new RemoteContainer('s:S1', 'Space', ContainerType::Space, null, false, true));

        self::assertSame($space, $provider->findSource($credential, 's:S1'));
        self::assertNull($provider->findSource($credential, 's:S9'));
        $first = $provider->fetchSourceTasks($credential, $space, null, null);
        self::assertSame(['a'], array_map(static fn (RemoteTask $t): string => $t->externalId(), $first->tasks));
        self::assertFalse($first->lastPage);
        self::assertSame('1', $first->nextCursor);
        $since = $provider->fetchSourceTasks($credential, $space, new DateTimeImmutable('@15'), '1');
        self::assertSame(['c'], array_map(static fn (RemoteTask $t): string => $t->externalId(), $since->tasks));
        self::assertTrue($since->lastPage);
        self::assertSame(['a', 'b'], iterator_to_array($provider->existingSourceTaskIds($credential, $space), false));
        self::assertSame(['a'], iterator_to_array($provider->existingSourceTaskIds($credential, $list), false));
        self::assertSame('done', $provider->listStatuses($credential, $space)[0]->name);
        self::assertSame([], $provider->listStatuses($credential, $list));
        self::assertSame('L7', $provider->resolveListFromUrl('https://app.clickup.com/1/v/li/L7'));
        self::assertNull($provider->resolveListFromUrl('https://app.clickup.com/1/v/b/L7'));
        self::assertSame(['L1'], array_map(static fn (RemoteContainer $c): string => $c->id, $provider->searchLists($credential, 'ROAD')));
        self::assertSame([], $provider->searchLists($credential, 'road', 0));
    }

    public function testThe123Script(): void
    {
        $credential = new ProviderCredential('pk_test_token', 'c');
        $provider   = (new InMemoryTaskProvider(pageSize: 1))
            ->addContainer(new RemoteContainer('L1', 'List 1', ContainerType::List, null, true, false))
            ->addContainer(new RemoteContainer('L2', 'List 2', ContainerType::List, null, false, false))
            ->putTask('L1', $this->task('a', 10))
            ->putTask('L1', $this->task('b', 20))
            ->setComments('a', []);
        self::assertSame('clickup', $provider->id());
        self::assertSame('task_board.sync.provider.clickup', $provider->labelKey());
        self::assertSame('clickup', $provider->importScope($credential));
        self::assertSame('1', $provider->listContainers($credential)->nextCursor);
        self::assertNull($provider->listContainers($credential, null, '1')->nextCursor);
        self::assertSame('L1', $provider->findContainer($credential, 'L1')?->id);
        self::assertNull($provider->findContainer($credential, 'L2'), 'not selectable');
        self::assertSame('1', $provider->fetchTasks($credential, 'L1', null, null)->nextCursor);
        self::assertTrue($provider->fetchTasks($credential, 'L1', new DateTimeImmutable('@15'), null)->lastPage);
        self::assertSame([], $provider->fetchComments($credential, 'a'));
        $provider->removeTask('L1', 'b');
        self::assertSame(['a'], iterator_to_array($provider->existingTaskIds($credential, 'L1'), false));
        $provider->failNext(null)->failNext(new ProviderException(ProviderErrorCode::Timeout));
        $provider->findContainer($credential, 'L1');
        $this->expectException(ProviderException::class);
        $provider->findContainer($credential, 'L1');
    }

    public function testValueObjects(): void
    {
        self::assertTrue(RemoteRole::Owner->isAdmin());
        self::assertTrue(RemoteRole::Admin->isAdmin());
        self::assertFalse(RemoteRole::Guest->isAdmin());
        self::assertTrue(RemoteStatusType::Closed->isDone());
        self::assertFalse(RemoteStatusType::Open->isDone());

        foreach (['s:1' => RemoteLocationKind::Space, 'f:2' => RemoteLocationKind::Folder, 'L3' => RemoteLocationKind::List] as $id => $kind) {
            $location = RemoteLocation::fromContainerId($id);
            self::assertNotNull($location);
            self::assertSame($kind, $location->kind);
            self::assertSame($id, $location->containerId());
            $container = (new RemoteSource($location, 'Name', null, ['Top'], null, 4))->container();
            self::assertSame($id, $container->id);
            self::assertSame(['Top'], $container->path);
            self::assertSame(4, $container->taskCount);
            self::assertTrue($container->selectable);
        }
        self::assertSame(ContainerType::Folder, (new RemoteSource(new RemoteLocation(RemoteLocationKind::Folder, '1'), 'F'))->container()->type);
        self::assertSame(ContainerType::Space, (new RemoteSource(new RemoteLocation(RemoteLocationKind::Space, '1'), 'S'))->container()->type);
        self::assertSame(ContainerType::List, (new RemoteSource(RemoteLocation::list('1'), 'L'))->container()->type);
        self::assertNull(RemoteLocation::fromContainerId('w:1'));
        self::assertNull(RemoteLocation::fromContainerId('bad id!'));

        $ref = new RemotePersonRef('1', 'User 1');
        self::assertNull($ref->email);
    }

    private function task(string $id, int $updated, bool $archived = false): RemoteTask
    {
        return new RemoteTask(new ImportedTaskDto(title: 'Task ' . $id, externalId: $id), new DateTimeImmutable('@' . $updated), $archived);
    }
}
