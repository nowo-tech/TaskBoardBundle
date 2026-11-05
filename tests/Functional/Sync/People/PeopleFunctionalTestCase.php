<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Tests\Functional\Sync\People;

use DateTimeImmutable;
use Nowo\TaskBoardBundle\Entity\Task;
use Nowo\TaskBoardBundle\Entity\TaskBoard;
use Nowo\TaskBoardBundle\Event\TaskBoardEvents;
use Nowo\TaskBoardBundle\Security\TaskBoardResourceAccessChecker;
use Nowo\TaskBoardBundle\Sync\Entity\TaskBoardConnection;
use Nowo\TaskBoardBundle\Sync\Entity\TaskSyncDirectory;
use Nowo\TaskBoardBundle\Sync\Entity\TaskSyncItem;
use Nowo\TaskBoardBundle\Sync\People\TaskSyncPeopleDirectoryService;
use Nowo\TaskBoardBundle\Sync\People\TaskSyncPersonRepository;
use Nowo\TaskBoardBundle\Tests\Functional\Sync\SyncFunctionalTestCase;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

/**
 * Since 1.25: the people tests — the directory services built around a scriptable resolver, and the audit events.
 */
abstract class PeopleFunctionalTestCase extends SyncFunctionalTestCase
{
    protected PeopleStubResolver $resolver;

    /** @var list<array{0: string, 1: object}> */
    protected array $events = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->resolver = new PeopleStubResolver();
        $this->events   = [];
        foreach ([TaskBoardEvents::SYNC_PERSON_MAPPED, TaskBoardEvents::SYNC_PERSON_ANONYMISED, TaskBoardEvents::SYNC_PEOPLE_REVIEWED, TaskBoardEvents::SYNC_PERSON_REASSIGNED, TaskBoardEvents::SYNC_PEOPLE_REPROJECTED] as $name) {
            $this->dispatcher()->addListener($name, function (object $event) use ($name): void {
                $this->events[] = [$name, $event];
            });
        }
    }

    protected function dispatcher(): EventDispatcherInterface
    {
        $dispatcher = static::getContainer()->get('event_dispatcher');
        self::assertInstanceOf(EventDispatcherInterface::class, $dispatcher);

        return $dispatcher;
    }

    protected function accessChecker(): TaskBoardResourceAccessChecker
    {
        $checker = static::getContainer()->get(TaskBoardResourceAccessChecker::class);
        self::assertInstanceOf(TaskBoardResourceAccessChecker::class, $checker);

        return $checker;
    }

    protected function repository(): TaskSyncPersonRepository
    {
        return new TaskSyncPersonRepository($this->em);
    }

    protected function directories(): TaskSyncPeopleDirectoryService
    {
        return new TaskSyncPeopleDirectoryService($this->em, $this->repository(), $this->resolver, $this->accessChecker(), $this->dispatcher());
    }

    protected function connection(TaskBoard $board, ?TaskSyncDirectory $directory = null, string $list = self::LIST): TaskBoardConnection
    {
        $connection = new TaskBoardConnection(board: $board, provider: 'clickup', importScope: 'clickup', connectionRef: 'conn-1', containerId: $list, containerLabel: 'Backlog', createdBy: $this->owner);
        $connection->setDirectory($directory);
        $this->em->persist($connection);
        $this->em->flush();

        return $connection;
    }

    protected function item(TaskBoardConnection $connection, Task $task, string $externalId): TaskSyncItem
    {
        $item = new TaskSyncItem($connection, $connection->getBoard()->getId(), $task, $externalId, new DateTimeImmutable('@1790000000'));
        $this->em->persist($item);
        $this->em->flush();

        return $item;
    }

    /**
     * The recorded events of one name.
     *
     * @return list<object>
     */
    protected function eventsNamed(string $name): array
    {
        $found = [];
        foreach ($this->events as [$eventName, $event]) {
            if ($eventName === $name) {
                $found[] = $event;
            }
        }

        return $found;
    }
}
