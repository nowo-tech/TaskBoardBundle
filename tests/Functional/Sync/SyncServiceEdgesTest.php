<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Tests\Functional\Sync;

use LogicException;
use Nowo\TaskBoardBundle\Entity\Task;
use Nowo\TaskBoardBundle\Entity\TaskBoard;
use Nowo\TaskBoardBundle\Event\TaskBoardEvents;
use Nowo\TaskBoardBundle\Repository\TaskBoardRepositoryInterface;
use Nowo\TaskBoardBundle\Sync\Dto\SyncError;
use Nowo\TaskBoardBundle\Sync\Dto\SyncRequest;
use Nowo\TaskBoardBundle\Sync\Dto\SyncStatus;
use Nowo\TaskBoardBundle\Sync\Dto\SyncTrigger;
use Nowo\TaskBoardBundle\Sync\Entity\TaskBoardConnection;
use Nowo\TaskBoardBundle\Sync\Entity\TaskSyncRun;
use Nowo\TaskBoardBundle\Sync\Event\SyncPageDoneEvent;
use Nowo\TaskBoardBundle\Sync\Event\SyncStartedEvent;
use Nowo\TaskBoardBundle\Sync\Hook\InlineSyncRunner;
use Nowo\TaskBoardBundle\Sync\Hook\SyncActorResolverInterface;
use Nowo\TaskBoardBundle\Sync\Provider\ContainerType;
use Nowo\TaskBoardBundle\Sync\Provider\ProviderCredential;
use Nowo\TaskBoardBundle\Sync\Provider\RemoteContainer;
use Nowo\TaskBoardBundle\Sync\Repository\SyncStore;
use Nowo\TaskBoardBundle\Sync\TaskBoardSyncService;
use Nowo\TaskBoardBundle\Sync\Testing\InMemoryTaskProvider;
use Nowo\TaskBoardBundle\Sync\View\ConnectionBadgeProvider;
use Nowo\TaskBoardBundle\Tests\Functional\App\TestCredentialResolver;
use ReflectionMethod;
use stdClass;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

use function array_map;

/**
 * Since 1.23: the engine's edges — a second link, cancelling between pages, a deletions scan that fails, task events
 * whose task is gone, a connection removed during the run, a non-entity actor, the inline runner without an actor and
 * the badge without a user.
 */
final class SyncServiceEdgesTest extends SyncFunctionalTestCase
{
    public function testABoardIsLinkedOnce(): void
    {
        $board = $this->board();
        $this->put($this->remote('l1', 'One', 1));
        $this->sync($board);
        $container = new RemoteContainer(self::LIST, 'Backlog', ContainerType::List, null, true, false);

        $this->expectException(LogicException::class);
        $this->service()->link($this->freshBoard($board), $this->provider, new ProviderCredential(TestCredentialResolver::$secret, 'conn-1'), $container, $this->owner);
    }

    public function testACancelRequestStopsTheRunBeforeTheNextPage(): void
    {
        $board = $this->board();
        $small = $this->smallProvider();
        foreach ([1, 2, 3] as $i) {
            $small->putTask(self::LIST, $this->remote('c' . $i, 'Task ' . $i, $i));
        }
        $service = $this->service();
        $this->dispatcher()->addListener(TaskBoardEvents::SYNC_PAGE_DONE, static function (SyncPageDoneEvent $event) use ($service): void {
            $service->requestCancel($event->board, $event->progress->runId);
        });

        self::assertSame(SyncStatus::Cancelled, $this->sync($board, $small)->status);
    }

    public function testAFailingDeletionsScanArchivesNothingAndARateLimitPauses(): void
    {
        $board = $this->board();
        $this->put($this->remote('s1', 'One', 1));
        $this->sync($board);

        $this->provider->removeTask(self::LIST, 's1');
        $failing = new RepeatingProvider($this->provider, repeat: false, failIds: true);
        $report  = $this->sync($board, $failing, full: true, allowMassArchive: true);
        self::assertSame(0, $report->archived);
        self::assertContains('deletions_skipped', array_map(static fn (SyncError $w): string => $w->code, $report->warnings));

        $limited = new RepeatingProvider($this->provider, repeat: false, failIds: true, rateLimited: true);
        self::assertSame(SyncStatus::RateLimited, $this->sync($board, $limited, full: true)->status);
    }

    public function testTaskEventsOfATaskRemovedMeanwhileAreDropped(): void
    {
        $board = $this->board();
        $this->put($this->remote('e1', 'One', 1), $this->remote('e2', 'Two', 2));
        $this->sync($board);

        $em       = $this->em;
        $archived = 0;
        $this->dispatcher()->addListener(TaskBoardEvents::TASK_REMOTE_ARCHIVED, static function () use ($em, &$archived): void {
            ++$archived;
            $other = $em->getRepository(Task::class)->findOneBy(['title' => 'Two']);
            if ($other instanceof Task) {
                $em->remove($other);
                $em->flush();
            }
        });
        $this->put($this->remote('e1', 'One', 3, archived: true), $this->remote('e2', 'Two', 4, archived: true));
        $this->sync($board);
        self::assertSame(1, $archived, 'the second task was removed before its event');
    }

    public function testAConnectionRemovedDuringTheRunFailsIt(): void
    {
        $board = $this->board();
        $small = $this->smallProvider();
        foreach ([1, 2, 3] as $i) {
            $small->putTask(self::LIST, $this->remote('r' . $i, 'Task ' . $i, $i));
        }
        $connection = $this->em->getConnection();
        $this->dispatcher()->addListener(TaskBoardEvents::SYNC_PAGE_DONE, static function () use ($connection): void {
            $connection->executeStatement('DELETE FROM task_board_connections');
        });

        $report = $this->sync($board, $small);
        self::assertSame(SyncStatus::Failed, $report->status);
        self::assertSame('internal', $report->errors[0]->code);
    }

    public function testTheHistoryIsPrunedToItsSize(): void
    {
        $board = $this->board();
        $this->put($this->remote('h1', 'One', 1));
        $this->sync($board);
        $this->sync($board);
        $this->sync($board);
        $connection = $this->connectionOf($board);
        self::assertInstanceOf(TaskBoardConnection::class, $connection);
        $store = self::getContainer()->get(SyncStore::class);
        self::assertInstanceOf(SyncStore::class, $store);
        $store->prune($connection, 1);
        self::assertCount(1, $this->em->getRepository(TaskSyncRun::class)->findAll());
    }

    public function testANonEntityActorHasNoIdentity(): void
    {
        $identity = new ReflectionMethod(TaskBoardSyncService::class, 'identity');
        self::assertNull($identity->invoke($this->service(), new stdClass()));
    }

    public function testTheInlineRunnerNeedsAnActorAndTheBadgeAUser(): void
    {
        $board  = $this->board();
        $boards = self::getContainer()->get(TaskBoardRepositoryInterface::class);
        self::assertInstanceOf(TaskBoardRepositoryInterface::class, $boards);
        $runner = new InlineSyncRunner($this->service(), $boards, new class implements SyncActorResolverInterface {
            public function actorFor(TaskBoard $board, ?string $actorId = null): ?object
            {
                return null;
            }
        });
        $runner->dispatch(new SyncRequest($board->getId(), null, SyncTrigger::Manual));
        self::assertCount(0, $this->em->getRepository(TaskSyncRun::class)->findAll(), 'no actor, no run');

        $badge = self::getContainer()->get(ConnectionBadgeProvider::class);
        self::assertInstanceOf(ConnectionBadgeProvider::class, $badge);
        self::assertNull($badge->forBoard($board), 'nobody is logged in');
    }

    public function testAFailureBeforeThePhasesWhoseHistoryCannotBeSavedStillFailsCleanly(): void
    {
        $board = $this->board();
        $this->put($this->remote('h1', 'One', 1));
        $em = $this->em;
        // A listener breaks the entity manager (a duplicate slug in the tenant): the run fails, its history cannot be saved.
        $board->setTenantKey('tenant-a');
        $em->flush();
        $this->dispatcher()->addListener(TaskBoardEvents::SYNC_STARTED, static function (SyncStartedEvent $event) use ($em): void {
            $em->persist((new TaskBoard('Dup', $event->board->getSlug(), $event->board->getCreator()))->setTenantKey('tenant-a'));
            $em->flush();
        });

        $report = $this->sync($board);
        self::assertSame(SyncStatus::Failed, $report->status);
        // Since 2.15.1 (audit OM TB-M1): the failed run reopens the manager it closed (worker mode, kernel not reset).
        self::assertTrue(self::getContainer()->get('doctrine')->getManager()->isOpen());
    }

    private function smallProvider(): InMemoryTaskProvider
    {
        $small = new InMemoryTaskProvider(pageSize: 1);
        $small->addContainer(new RemoteContainer(self::LIST, 'Backlog', ContainerType::List, null, true, false));

        return $small;
    }

    private function dispatcher(): EventDispatcherInterface
    {
        $dispatcher = self::getContainer()->get('event_dispatcher');
        self::assertInstanceOf(EventDispatcherInterface::class, $dispatcher);

        return $dispatcher;
    }
}
