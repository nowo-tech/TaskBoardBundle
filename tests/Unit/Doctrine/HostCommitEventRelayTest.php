<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Tests\Unit\Doctrine;

use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\ClassMetadataFactory;
use Doctrine\Persistence\ManagerRegistry;
use Nowo\TaskBoardBundle\Doctrine\HostCommitEventRelay;
use Nowo\TaskBoardBundle\Doctrine\TaskBoardEntityManager;
use Nowo\TaskBoardBundle\Event\TaskBoardEvents;
use Nowo\TaskBoardBundle\Tests\Functional\App\RecordingLogger;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use stdClass;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\HttpKernel\KernelEvents;

use function str_contains;

/**
 * Since 2.29.0 (DDD-host checklist B4): the relay holds the change events while the application's transaction is open,
 * releases them after its commit at an idle point, and drops them on a rollback or at a boundary (worker mode).
 */
final class HostCommitEventRelayTest extends TestCase
{
    private int $level = 0;

    private bool $broken = false;

    /** @var list<string> */
    private array $seen = [];

    private EventDispatcher $inner;

    private RecordingLogger $logger;

    private HostCommitEventRelay $relay;

    protected function setUp(): void
    {
        $connection = $this->createStub(Connection::class);
        $connection->method('getTransactionNestingLevel')->willReturnCallback(function (): int {
            if ($this->broken) {
                throw new RuntimeException('gone');
            }

            return $this->level;
        });
        $metadata = $this->createStub(ClassMetadataFactory::class);
        $metadata->method('isTransient')->willReturn(false);
        $manager = $this->createStub(EntityManagerInterface::class);
        $manager->method('getConnection')->willReturn($connection);
        $manager->method('getMetadataFactory')->willReturn($metadata);
        $registry = $this->createStub(ManagerRegistry::class);
        $registry->method('getManager')->willReturn($manager);

        $this->inner = new EventDispatcher();
        foreach ([TaskBoardEvents::TASK_CREATED, TaskBoardEvents::TASK_UPDATED, TaskBoardEvents::TASK_ACCESS_CHECK] as $name) {
            $this->inner->addListener($name, function (stdClass $event) use ($name): void {
                $this->seen[] = $name . ':' . $event->id;
            });
        }
        $this->logger = new RecordingLogger();
        $this->relay  = new HostCommitEventRelay($this->inner, new TaskBoardEntityManager($registry), $this->logger);
    }

    public function testOutsideATransactionEverythingPassesAtOnce(): void
    {
        $this->relay->dispatch($this->event('1'), TaskBoardEvents::TASK_CREATED);
        self::assertSame(['nowo_task_board.task_created:1'], $this->seen);
        self::assertSame([KernelEvents::RESPONSE => ['onResponse', -1024]], HostCommitEventRelay::getSubscribedEvents());
    }

    public function testChecksPassAndChangesWaitForTheCommit(): void
    {
        $this->level = 1;
        $this->relay->dispatch($this->event('a'), TaskBoardEvents::TASK_ACCESS_CHECK);
        $this->relay->dispatch($this->event('1'), TaskBoardEvents::TASK_CREATED);
        self::assertSame(['nowo_task_board.task_access_check:a'], $this->seen);
        self::assertSame(['held' => 1, 'released' => 0], $this->relay->pending());

        $this->relay->connectionCommitted();
        // Still inside the commit call: not dispatched until an idle point.
        self::assertSame(['held' => 0, 'released' => 1], $this->relay->pending());
        $this->level = 0;
        $this->relay->dispatch($this->event('2'), TaskBoardEvents::TASK_UPDATED);
        self::assertSame(['nowo_task_board.task_access_check:a', 'nowo_task_board.task_created:1', 'nowo_task_board.task_updated:2'], $this->seen);
    }

    public function testASavepointRollbackDropsItsLevelOnlyAndAReleaseMovesItDown(): void
    {
        $this->level = 1;
        $this->relay->dispatch($this->event('outer'), TaskBoardEvents::TASK_CREATED);
        $this->level = 2;
        $this->relay->dispatch($this->event('inner'), TaskBoardEvents::TASK_CREATED);
        $this->relay->connectionRolledBack(2);
        self::assertSame(['held' => 1, 'released' => 0], $this->relay->pending());

        $this->relay->dispatch($this->event('kept'), TaskBoardEvents::TASK_CREATED);
        $this->relay->savepointReleased(2);
        $this->relay->savepointReleased(1);
        $this->relay->connectionRolledBack(2);
        self::assertSame(['held' => 2, 'released' => 0], $this->relay->pending(), 'moved to level 1, not dropped by a level-2 rollback');

        $this->relay->connectionRolledBack(null);
        self::assertSame(['held' => 0, 'released' => 0], $this->relay->pending());
        $this->relay->connectionCommitted();
        self::assertSame([], $this->seen);
    }

    public function testTheResponseReleasesAndAFailingListenerIsLogged(): void
    {
        $this->inner->addListener(TaskBoardEvents::TASK_CREATED, static function (): never {
            throw new RuntimeException('listener');
        });
        $this->level = 1;
        $this->relay->dispatch($this->event('1'), TaskBoardEvents::TASK_CREATED);
        $this->relay->connectionCommitted();
        $kernel = $this->createStub(HttpKernelInterface::class);
        $this->relay->onResponse(new ResponseEvent($kernel, new Request(), HttpKernelInterface::SUB_REQUEST, new Response()));
        $this->relay->onResponse(new ResponseEvent($kernel, new Request(), HttpKernelInterface::MAIN_REQUEST, new Response()));
        self::assertSame(['held' => 0, 'released' => 1], $this->relay->pending(), 'the connection is still busy');

        $this->level = 0;
        $this->relay->onResponse(new ResponseEvent($kernel, new Request(), HttpKernelInterface::SUB_REQUEST, new Response()));
        self::assertSame([], $this->seen, 'a sub-request is no idle point');
        $this->relay->onResponse(new ResponseEvent($kernel, new Request(), HttpKernelInterface::MAIN_REQUEST, new Response()));
        self::assertSame(['nowo_task_board.task_created:1'], $this->seen);
        self::assertTrue($this->logged('error', 'listener failed after the change was committed'));
    }

    public function testABoundaryReleasesWhatCommittedAndDropsTheRest(): void
    {
        $this->level = 1;
        $this->relay->dispatch($this->event('committed'), TaskBoardEvents::TASK_CREATED);
        $this->relay->connectionCommitted();
        $this->relay->dispatch($this->event('open'), TaskBoardEvents::TASK_CREATED);
        $this->relay->reset();

        self::assertSame(['nowo_task_board.task_created:committed'], $this->seen);
        self::assertSame(['held' => 0, 'released' => 0], $this->relay->pending());
        self::assertTrue($this->logged('warning', 'dropped {count} task event'));
        $this->relay->reset();
    }

    public function testAnUnreadableConnectionCountsAsIdle(): void
    {
        $this->broken = true;
        $this->relay->dispatch($this->event('1'), TaskBoardEvents::TASK_CREATED);
        self::assertSame(['nowo_task_board.task_created:1'], $this->seen);
    }

    private function logged(string $level, string $text): bool
    {
        foreach ($this->logger->records as $record) {
            if ($record['level'] === $level && str_contains($record['message'], $text)) {
                return true;
            }
        }

        return false;
    }

    private function event(string $id): stdClass
    {
        $event     = new stdClass();
        $event->id = $id;

        return $event;
    }
}
