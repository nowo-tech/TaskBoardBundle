<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Tests\Functional\Api;

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\ORM\Event\OnFlushEventArgs;
use Doctrine\ORM\Events;
use Nowo\TaskBoardBundle\Entity\Task;
use Nowo\TaskBoardBundle\Entity\TaskBoard;
use Nowo\TaskBoardBundle\Entity\TaskChangeHistory;
use Nowo\TaskBoardBundle\Enum\TaskChangeType;
use Nowo\TaskBoardBundle\Event\TaskAccessCheckEvent;
use Nowo\TaskBoardBundle\Event\TaskBoardEvents;
use Nowo\TaskBoardBundle\Event\TaskUpdatedEvent;
use Nowo\TaskBoardBundle\Import\TaskImportOrchestrator;
use Nowo\TaskBoardBundle\Import\TaskImportSource;
use Nowo\TaskBoardBundle\Move\TaskMovePlanner;
use Nowo\TaskBoardBundle\Move\TaskMoverInterface;
use Nowo\TaskBoardBundle\Provenance\WriteChannel;
use Nowo\TaskBoardBundle\Service\TaskEventDeferral;
use Nowo\TaskBoardBundle\Tests\Functional\Api\App\ApiTestHost;
use Nowo\TaskBoardBundle\Tests\Functional\App\PathWriteProvenance;
use Nowo\TaskBoardBundle\Tree\TaskReparenterInterface;
use RuntimeException;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

use function array_filter;
use function implode;
use function proc_close;
use function proc_open;
use function str_contains;
use function stream_get_contents;
use function usleep;

/**
 * Audit PX (TaskBoard 2.20.0, PR #116): the auditor's PoCs turned around: each test asserts the fixed behaviour.
 */
final class ApiTaskReparentAuditTest extends ApiTestCase
{
    private TaskBoard $roadmap;

    protected function setUp(): void
    {
        PathWriteProvenance::reset();
        self::$hostConfig = ['write_provenance_provider' => 'app.write_provenance'];
        parent::setUp();
        ApiTestHost::$tenants = ['tenant-a'];
        $this->roadmap        = $this->board('Roadmap', tenantKey: 'tenant-a');
        $this->issueToken();
    }

    protected function tearDown(): void
    {
        PathWriteProvenance::reset();
        parent::tearDown();
    }

    /**
     * PX-L1 (OG-1): every check of the update runs before the parent change, and a rollback puts memory back too. A
     * later board move of the epic, on the same kept-alive manager, then never takes the story with it.
     */
    public function testARefusedUpdateLeavesNeitherTheDatabaseNorMemoryChanged(): void
    {
        $epic  = $this->task($this->roadmap, 'Epic');
        $story = $this->task($this->roadmap, 'Story');
        $other = $this->board('Other', tenantKey: 'tenant-a');

        $this->send('PUT', '/api/tasks/v2/task/' . $story->getId(), ['parent' => $epic->getId(), 'status' => 'Missing column']);
        self::assertResponseStatusCodeSame(400);
        self::assertNull($story->getParent());
        self::assertFalse($epic->getSubtasks()->contains($story), 'the epic does not list the story');
        self::assertSame(0, (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM task_board_task_change_history WHERE change_type = ?', ['parent']));

        $planner = self::getContainer()->get(TaskMovePlanner::class);
        $mover   = self::getContainer()->get(TaskMoverInterface::class);
        self::assertInstanceOf(TaskMovePlanner::class, $planner);
        self::assertInstanceOf(TaskMoverInterface::class, $mover);
        // The same objects in a kept-alive standard host; the private manager (dropped at the request boundary) reloads them.
        $epic  = $this->em->find(Task::class, $epic->getId());
        $other = $this->em->find(TaskBoard::class, $other->getId());
        $owner = $this->em->find($this->owner::class, $this->owner->getId());
        self::assertInstanceOf(Task::class, $epic);
        self::assertInstanceOf(TaskBoard::class, $other);
        self::assertNotNull($owner);
        $plan = $planner->plan($epic, $other, [], $owner);
        self::assertNotContains($story->getId(), $plan->taskIds);
        $mover->move($epic, $other, [], $plan->fingerprint, $owner);
        self::assertSame($this->roadmap->getId(), (string) $this->em->getConnection()->fetchOne('SELECT board_id FROM task_board_tasks WHERE id = ?', [$story->getId()]));
    }

    /** PX-L1: a failure that only comes while writing, after the parent change: the database and memory go back. */
    public function testAFailureAfterTheParentChangePutsMemoryBack(): void
    {
        $old   = $this->task($this->roadmap, 'Old parent');
        $epic  = $this->task($this->roadmap, 'Epic');
        $story = $this->task($this->roadmap, 'Story', parent: $old);
        $old->getSubtasks()->add($story);

        // The rename's save fails, after the parent change was saved in the same transaction.
        $flushes = new class {
            public function onFlush(OnFlushEventArgs $args): void
            {
                $work = $args->getObjectManager()->getUnitOfWork();
                foreach ($work->getScheduledEntityUpdates() as $entity) {
                    if ($entity instanceof Task && isset($work->getEntityChangeSet($entity)['title'])) {
                        throw new RuntimeException('the rename fails');
                    }
                }
            }
        };
        $this->em->getEventManager()->addEventListener(Events::onFlush, $flushes);
        $this->send('PUT', '/api/tasks/v2/task/' . $story->getId(), ['parent' => $epic->getId(), 'name' => 'Renamed']);
        self::assertResponseStatusCodeSame(500);
        $this->em->getEventManager()->removeEventListener(Events::onFlush, $flushes);
        self::assertSame($old->getId(), (string) $this->em->getConnection()->fetchOne('SELECT parent_id FROM task_board_tasks WHERE id = ?', [$story->getId()]));
        self::assertFalse($epic->getSubtasks()->contains($story), 'memory is back to the old tree');
        self::assertTrue($old->getSubtasks()->contains($story));

    }

    /** PX-L2: two parents with the same title: the history row and the provenance are recorded (ids differ). */
    public function testAMoveBetweenParentsWithTheSameTitleIsRecorded(): void
    {
        PathWriteProvenance::$label = 'CI token';
        $first                      = $this->task($this->roadmap, 'Sprint');
        $second                     = $this->task($this->roadmap, 'Sprint');
        $task                       = $this->task($this->roadmap, 'Story', parent: $first);

        $this->send('PUT', '/api/tasks/v2/task/' . $task->getId(), ['parent' => $second->getId()]);

        self::assertResponseIsSuccessful();
        $this->em->clear();
        $rows = $this->em->getRepository(TaskChangeHistory::class)->findBy(['task' => $task->getId(), 'changeType' => TaskChangeType::Parent]);
        self::assertCount(1, $rows);
        self::assertSame(['Sprint', 'Sprint', 'task:' . $second->getId(), WriteChannel::Api, 'CI token'], [$rows[0]->getOldValue(), $rows[0]->getNewValue(), $rows[0]->getContext(), $rows[0]->getChannel(), $rows[0]->getActorLabel()]);
    }

    /**
     * PX-M2: the import's parent change goes through the port, on committed, locked rows: a parent change committed by
     * another process (the API; raw SQL here) that the import's manager has not seen can no longer close a cycle.
     */
    public function testAnImportOnAStaleMapCannotCloseACycle(): void
    {
        $import = self::getContainer()->get(TaskImportOrchestrator::class);
        self::assertInstanceOf(TaskImportOrchestrator::class, $import);
        $import->import($this->roadmap, TaskImportSource::TaskBoardCsv, "id,parent,title\na,,A\nb,,B\n", 'plan.csv', $this->owner);
        $a = $this->em->getRepository(Task::class)->findOneBy(['title' => 'A']);
        $b = $this->em->getRepository(Task::class)->findOneBy(['title' => 'B']);
        self::assertInstanceOf(Task::class, $a);
        self::assertInstanceOf(Task::class, $b);
        $connection = $this->em->getConnection();
        $connection->executeStatement('UPDATE task_board_tasks SET parent_id = ? WHERE id = ?', [$a->getId(), $b->getId()]);

        $result = $import->import($this->roadmap, TaskImportSource::TaskBoardCsv, "id,parent,title\na,b,A\n", 'plan.csv', $this->owner);

        self::assertNotSame([], array_filter($result->warnings, static fn (string $warning): bool => str_contains($warning, 'its own ancestor')));
        self::assertNull($connection->fetchOne('SELECT parent_id FROM task_board_tasks WHERE id = ?', [$a->getId()]), 'no A ↔ B cycle');
        self::assertSame($a->getId(), (string) $connection->fetchOne('SELECT parent_id FROM task_board_tasks WHERE id = ?', [$b->getId()]));
    }

    /**
     * PX-L3: inside a host's own transaction wrapped in `TaskEventDeferral::defer()`, TASK_UPDATED waits for the host's
     * commit, and a host rollback drops it.
     */
    public function testInsideADeferredHostTransactionTheEventWaitsForTheCommit(): void
    {
        $epic       = $this->task($this->roadmap, 'Epic');
        $story      = $this->task($this->roadmap, 'Story');
        $connection = $this->em->getConnection();
        $levels     = [];
        $this->dispatcher()->addListener(TaskBoardEvents::TASK_UPDATED, static function (TaskUpdatedEvent $event) use (&$levels, $connection): void {
            $levels[] = $connection->getTransactionNestingLevel();
        });
        $reparenter = self::getContainer()->get(TaskReparenterInterface::class);
        $deferral   = self::getContainer()->get(TaskEventDeferral::class);
        self::assertInstanceOf(TaskReparenterInterface::class, $reparenter);
        self::assertInstanceOf(TaskEventDeferral::class, $deferral);

        try {
            $deferral->defer(function () use ($connection, $reparenter, $story, $epic): never {
                $connection->beginTransaction();
                $reparenter->reparent($story->getId(), $epic->getId(), $this->owner);
                $connection->rollBack();

                throw new RuntimeException('the host gave up');
            });
        } catch (RuntimeException) {
        }
        self::assertSame([], $levels, 'rolled back: no event');

        $deferral->defer(function () use ($connection, $reparenter, $story, $epic): void {
            $connection->beginTransaction();
            $reparenter->reparent($story->getId(), $epic->getId(), $this->owner);
            $connection->commit();
        });
        self::assertSame([0], $levels, 'after the host commit');
    }

    /** PX-I3: echoing the parent the task already has needs no right on that parent (a client echoing the task). */
    public function testEchoingTheCurrentParentNeedsNoRightOnIt(): void
    {
        $epic   = $this->task($this->roadmap, 'Epic');
        $story  = $this->task($this->roadmap, 'Story', parent: $epic);
        $other  = $this->task($this->roadmap, 'Other');
        $epicId = $epic->getId();
        $this->dispatcher()->addListener(TaskBoardEvents::TASK_ACCESS_CHECK, static function (TaskAccessCheckEvent $event) use ($epicId): void {
            if ($event->getTask()->getId() === $epicId) {
                $event->grant();
                $event->markReadOnly();
            }
        });

        $this->send('PUT', '/api/tasks/v2/task/' . $story->getId(), ['parent' => $epicId, 'name' => 'Renamed']);
        self::assertResponseIsSuccessful();
        self::assertSame('Renamed', $this->json()['name']);

        // A real change to a parent the person may not edit is still refused.
        $this->send('PUT', '/api/tasks/v2/task/' . $other->getId(), ['parent' => $epicId]);
        $this->assertRefused(403, 'ACCESS_002');
    }

    /**
     * PX-M1 (MySQL only): a REAL InnoDB deadlock in the parent change. The client gets 409 PARENT_004, the connection is
     * back at nesting level 0, and the next request of the same kept-alive process is saved and answered 200.
     */
    public function testARealMySqlDeadlockLeavesTheConnectionClean(): void
    {
        $epic  = $this->task($this->roadmap, 'Epic');
        $story = $this->task($this->roadmap, 'Story');
        $this->deadlockWhile('task_board_tasks', 'title', $epic->getId(), $story->getId(), function () use ($story, $epic): void {
            $this->send('PUT', '/api/tasks/v2/task/' . $story->getId(), ['parent' => $epic->getId()]);
        });

        $this->assertRefused(409, 'PARENT_004');
        $this->assertTheNextWriteIsClean($story);
    }

    /**
     * PX-M1, the root cause since 2.16.0 (no `parent`): a `base_revision` rename whose flush is the deadlock victim (the
     * history row's foreign key waits on the user row the other transaction holds).
     */
    public function testARealMySqlDeadlockInABaseRevisionUpdateLeavesTheConnectionClean(): void
    {
        $story = $this->task($this->roadmap, 'Story');
        $this->deadlockWhile('app_user', 'email', (string) $this->owner->getId(), $story->getId(), function () use ($story): void {
            $this->send('PUT', '/api/tasks/v2/task/' . $story->getId(), ['name' => 'Renamed', 'base_revision' => 1]);
        });

        self::assertGreaterThanOrEqual(400, $this->client->getResponse()->getStatusCode());
        $this->assertTheNextWriteIsClean($story);
    }

    private function deadlockWhile(string $table, string $column, string $held, string $wanted, callable $request): void
    {
        $connection = $this->em->getConnection();
        if (!$connection->getDatabasePlatform() instanceof AbstractMySQLPlatform) {
            self::markTestSkipped('A real InnoDB deadlock: MySQL / MariaDB only.');
        }
        $fill = [];
        for ($i = 0; $i < 20; ++$i) {
            $fill[] = $this->task($this->roadmap, 'Fill ' . $i)->getId();
        }
        $params = $connection->getParams();
        $proc   = proc_open(['php', __DIR__ . '/../../Fixtures/Deadlock/hold_then_request.php', (string) ($params['host'] ?? ''), (string) ($params['port'] ?? 3306), (string) ($params['dbname'] ?? ''), (string) ($params['user'] ?? ''), (string) ($params['password'] ?? ''), $table, $column, $held, $wanted, implode(',', $fill)], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        self::assertIsResource($proc);
        usleep(600_000);
        $request();
        $other = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
        proc_close($proc);
        self::assertStringContainsString('other: got the lock', $other, 'the request was the deadlock victim');
    }

    private function assertTheNextWriteIsClean(Task $story): void
    {
        $connection = $this->em->getConnection();
        self::assertSame(0, $connection->getTransactionNestingLevel(), 'no level left behind');
        $updated = 0;
        $this->dispatcher()->addListener(TaskBoardEvents::TASK_UPDATED, static function () use (&$updated): void {
            ++$updated;
        });
        $this->send('PUT', '/api/tasks/v2/task/' . $story->getId(), ['name' => 'After the deadlock']);
        self::assertResponseIsSuccessful();
        self::assertSame('After the deadlock', $connection->fetchOne('SELECT title FROM task_board_tasks WHERE id = ?', [$story->getId()]));
        self::assertSame(1, $updated);
        self::assertSame(0, $connection->getTransactionNestingLevel());
    }

    private function dispatcher(): EventDispatcherInterface
    {
        $dispatcher = self::getContainer()->get('event_dispatcher');
        self::assertInstanceOf(EventDispatcherInterface::class, $dispatcher);

        return $dispatcher;
    }
}
