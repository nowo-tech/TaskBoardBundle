<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Tests\Functional\Sync;

use DateTimeImmutable;
use Doctrine\ORM\Event\OnFlushEventArgs;
use Doctrine\ORM\Events;
use Nowo\TaskBoardBundle\Entity\TaskBoard;
use Nowo\TaskBoardBundle\Event\TaskBoardEvents;
use Nowo\TaskBoardBundle\Sync\Entity\TaskBoardConnection;
use Nowo\TaskBoardBundle\Sync\Entity\TaskSyncComment;
use Nowo\TaskBoardBundle\Sync\Entity\TaskSyncDirectory;
use Nowo\TaskBoardBundle\Sync\Entity\TaskSyncItem;
use Nowo\TaskBoardBundle\Sync\Entity\TaskSyncMember;
use Nowo\TaskBoardBundle\Sync\Entity\TaskSyncPerson;
use Nowo\TaskBoardBundle\Sync\Entity\TaskSyncRun;
use Nowo\TaskBoardBundle\Sync\Exception\SyncBusyException;
use Nowo\TaskBoardBundle\Sync\Provider\RemoteComment;
use Nowo\TaskBoardBundle\Tests\Functional\App\PredictableCsrfTokenManager;
use RuntimeException;

/**
 * Since 1.25 (audit FP-L5): `unlink()` holds the board's sync lock and is one transaction. A running sync refuses it;
 * rows of the connection still loaded in the entity manager never leave a half-done unlink.
 */
final class SyncUnlinkTest extends SyncPeopleFunctionalTestCase
{
    public function testARunningSyncRefusesTheUnlinkAndAStaleLockDoesNot(): void
    {
        $board = $this->linkedBoard();
        $this->holdLock('other-run', new DateTimeImmutable());

        try {
            $this->service()->unlink($this->freshBoard($board), $this->owner);
            self::fail('a live lock refuses the unlink');
        } catch (SyncBusyException $busy) {
            self::assertSame($board->getId(), $busy->boardId);
        }
        self::assertInstanceOf(TaskBoardConnection::class, $this->connectionOf($board));

        // The connection page says so and keeps the link.
        $this->login($this->owner);
        $this->client->request('POST', '/tools/task-board/board/' . $board->getId() . '/connection/unlink', ['_token' => PredictableCsrfTokenManager::value('task_board_connection_unlink')]);
        self::assertResponseRedirects();
        self::assertInstanceOf(TaskBoardConnection::class, $this->connectionOf($board));

        // A lock whose heartbeat is older than the stale limit belongs to a dead run.
        $this->holdLock('dead-run', new DateTimeImmutable('-1 hour'));
        $this->service()->unlink($this->freshBoard($board), $this->owner);
        self::assertNull($this->connectionOf($board));
        self::assertCount(1, $this->tasksOf($board), 'unlinking keeps the tasks');
    }

    public function testLoadedSyncRowsNeverLeaveAPartialUnlink(): void
    {
        $board = $this->linkedBoard();
        $this->em->clear();
        $this->refreshOwner();
        // Everything the sync wrote, loaded (as a page of a run or a view would leave it).
        self::assertNotSame([], $this->em->getRepository(TaskSyncItem::class)->findAll());
        self::assertNotSame([], $this->em->getRepository(TaskSyncComment::class)->findAll());
        self::assertNotSame([], $this->em->getRepository(TaskSyncMember::class)->findAll());
        self::assertNotSame([], $this->em->getRepository(TaskSyncRun::class)->findAll());
        $unlinked = 0;
        $this->dispatcher()->addListener(TaskBoardEvents::BOARD_CONNECTION_UNLINKED, static function () use (&$unlinked): void {
            ++$unlinked;
        });

        $this->service()->unlink($this->freshBoard($board), $this->owner);

        $this->em->clear();
        self::assertNull($this->connectionOf($board));
        self::assertSame(0, $this->rows(TaskSyncItem::class));
        self::assertSame(0, $this->rows(TaskSyncRun::class));
        $people = $this->em->getRepository(TaskSyncPerson::class)->findAll();
        // Unreferenced people go with the directory; the ones still referenced stay, anonymised (the references the
        // database cascades with the sync rows depend on the platform's foreign keys).
        self::assertTrue($people !== [] || $this->rows(TaskSyncDirectory::class) === 0, 'the directory was pruned');
        foreach ($people as $person) {
            self::assertTrue($person->isAnonymised(), 'the directory was pruned (people still referenced are anonymised)');
        }
        self::assertSame(1, $unlinked);
    }

    public function testAFailureInsideTheUnlinkRollsEverythingBackAndFreesTheLock(): void
    {
        $board    = $this->linkedBoard();
        $listener = new class {
            public function onFlush(OnFlushEventArgs $args): never
            {
                throw new RuntimeException('prune failed');
            }
        };
        $events = $this->em->getEventManager();
        $events->addEventListener(Events::onFlush, $listener);

        try {
            $this->service()->unlink($this->freshBoard($board), $this->owner);
            self::fail('the prune failure surfaces');
        } catch (RuntimeException $exception) {
            self::assertSame('prune failed', $exception->getMessage());
        } finally {
            $events->removeEventListener(Events::onFlush, $listener);
        }

        $connection = $this->connectionOf($board);
        self::assertInstanceOf(TaskBoardConnection::class, $connection, 'rolled back: still linked');
        self::assertNull($connection->getRunningRunId(), 'the lock is free again');
        self::assertGreaterThan(0, $this->rows(TaskSyncItem::class));
    }

    private function linkedBoard(): TaskBoard
    {
        $this->viewer('carol@example.test');
        $this->workspace([self::member('9001', 'carol@example.test')]);
        $board = $this->boardWithColumns('To do', 'Done');
        $this->put($this->row('t1', 'One', 1, creator: self::ref('9001', 'carol@example.test'), assignees: [self::ref('9001', 'carol@example.test')]));
        $this->provider->setComments('t1', [new RemoteComment('c1', 'Hi', null, null, new DateTimeImmutable('@1700000000'), [], self::ref('9001', 'carol@example.test'))]);
        $this->sync($board);

        return $board;
    }

    private function holdLock(string $runId, DateTimeImmutable $heartbeat): void
    {
        $this->em->createQuery('UPDATE ' . TaskBoardConnection::class . ' c SET c.runningRunId = :run, c.heartbeatAt = :at')
            ->setParameter('run', $runId)->setParameter('at', $heartbeat, 'datetime_immutable')->execute();
        $this->em->clear();
        $this->refreshOwner();
    }
}
