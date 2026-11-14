<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Tests\Functional\Api;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\EntityManagerInterface;
use Nowo\TaskBoardBundle\Entity\Task;
use Nowo\TaskBoardBundle\Entity\TaskBoard;
use Nowo\TaskBoardBundle\Entity\TaskMember;
use Nowo\TaskBoardBundle\Enum\TaskMemberRole;
use Nowo\TaskBoardBundle\Tests\Functional\Api\App\ApiTestHost;

/**
 * Audit PC-L4 (TaskBoard 2.17.0, spec 234 FR-018): the revision is incremented by the database (`revision = revision + 1`
 * inside the flush's transaction), never written as a value computed in PHP. An interleaving harness: a second writer
 * on its own entity manager (its own connection on MySQL / PostgreSQL / a file; the same in-memory SQLite connection
 * otherwise) loads the task, the first writer commits, then the second one saves what it loaded. Before 2.17.0 both
 * writes carried the same revision and an `If-Match` naming it passed on the second write's content.
 */
final class ApiRevisionRaceTest extends ApiTestCase
{
    private TaskBoard $roadmap;

    private ?Connection $otherConnection = null;

    protected function setUp(): void
    {
        parent::setUp();
        ApiTestHost::$tenants = ['tenant-a'];
        $this->roadmap        = $this->board('Roadmap', tenantKey: 'tenant-a');
        $this->issueToken();
    }

    protected function tearDown(): void
    {
        $this->otherConnection?->close();
        $this->otherConnection = null;
        parent::tearDown();
    }

    public function testAWriterThatLoadedTheTaskBeforeALockedWriteGetsTheNextRevision(): void
    {
        $task  = $this->task($this->roadmap, 'Raced');
        $other = $this->otherWriter();
        $stale = $other->find(Task::class, $task->getId());
        self::assertInstanceOf(Task::class, $stale);
        self::assertSame(1, $stale->getRevision());

        // A client with If-Match (the row locked) writes revision 2.
        $this->send('PUT', '/api/tasks/v2/task/' . $task->getId(), ['name' => 'Locked write'], ['HTTP_IF_MATCH' => '"1"']);
        self::assertResponseIsSuccessful();
        self::assertSame(2, $this->json()['revision']);

        // The board UI (no lock) saves what it loaded at revision 1.
        $stale->setDescription('Unlocked write');
        $other->flush();
        self::assertSame(3, $stale->getRevision(), 'The unlocked write is a new revision, not a second revision 2');
        self::assertSame(3, $this->revisionInDatabase($task));

        // The client that read revision 2 is told the task changed.
        $this->send('PUT', '/api/tasks/v2/task/' . $task->getId(), ['name' => 'Lost update'], ['HTTP_IF_MATCH' => '"2"']);
        self::assertResponseStatusCodeSame(409);
        self::assertSame(['err' => 'The task changed: read it again', 'ECODE' => 'TASK_409', 'current_revision' => 3], $this->json());

        $this->get('/api/tasks/v2/task/' . $task->getId());
        self::assertSame(['Locked write', 'Unlocked write', 3], [$this->json()['name'], $this->json()['description'], $this->json()['revision']]);
        self::assertResponseHeaderSame('ETag', '"3"');
    }

    public function testTwoUnlockedWritersNeverShareARevision(): void
    {
        $task  = $this->task($this->roadmap, 'Both');
        $other = $this->otherWriter();
        $stale = $other->find(Task::class, $task->getId());
        self::assertInstanceOf(Task::class, $stale);

        $task->setTitle('First');
        $this->em->flush();
        $stale->setDescription('Second');
        $other->flush();

        self::assertSame([2, 3, 3], [$task->getRevision(), $stale->getRevision(), $this->revisionInDatabase($task)]);
        // The first writer's copy is behind; its next write still starts from the database.
        $task->setTitle('Third');
        $this->em->flush();
        self::assertSame([4, 4], [$task->getRevision(), $this->revisionInDatabase($task)]);
    }

    public function testMembersBumpOncePerFlushAndPositionsNever(): void
    {
        $colleague = $this->user('colleague@example.com');
        $task      = $this->task($this->roadmap, 'Members');
        $other     = $this->otherWriter();
        $stale     = $other->find(Task::class, $task->getId());
        self::assertInstanceOf(Task::class, $stale);

        $task->setTitle('Renamed');
        $this->em->flush();

        // Two members and a field in one flush of the stale copy: one new revision on top of the database's.
        $otherOwner     = $other->find($this->owner::class, $this->owner->getId());
        $otherColleague = $other->find($colleague::class, $colleague->getId());
        self::assertNotNull($otherOwner);
        self::assertNotNull($otherColleague);
        $stale->addMember(new TaskMember($stale, $otherColleague, TaskMemberRole::Assignee));
        $stale->addMember(new TaskMember($stale, $otherOwner, TaskMemberRole::Watcher));
        $other->flush();
        self::assertSame([3, 3], [$stale->getRevision(), $this->revisionInDatabase($task)]);

        // Members only (one removed): one more.
        $member = $stale->getMembers()->first();
        self::assertInstanceOf(TaskMember::class, $member);
        $stale->getMembers()->removeElement($member);
        $other->remove($member);
        $other->flush();
        self::assertSame([4, 4], [$stale->getRevision(), $this->revisionInDatabase($task)]);

        // Members and a field together: still one.
        $stale->setDescription('Both');
        $stale->addMember(new TaskMember($stale, $otherColleague, TaskMemberRole::Watcher));
        $other->flush();
        self::assertSame([5, 5], [$stale->getRevision(), $this->revisionInDatabase($task)]);

        // A drag of another card renumbers its siblings: no revision, and the stale number is never written back.
        $task->setPosition(7);
        $this->em->flush();
        self::assertSame([2, 5], [$task->getRevision(), $this->revisionInDatabase($task)]);
        $stale->setPosition(8);
        $other->flush();
        self::assertSame([5, 5], [$stale->getRevision(), $this->revisionInDatabase($task)]);
    }

    public function testARolledBackWriteLeavesTheRevisionAsItWas(): void
    {
        $task = $this->task($this->roadmap, 'Rolled back');
        $this->em->getConnection()->beginTransaction();
        $task->setTitle('Never saved');
        $this->em->flush();
        self::assertSame(2, $task->getRevision());
        $this->em->getConnection()->rollBack();
        $this->em->clear();
        $task = $this->em->find(Task::class, $task->getId());
        self::assertInstanceOf(Task::class, $task);

        self::assertSame(['Rolled back', 1, 1], [$task->getTitle(), $task->getRevision(), $this->revisionInDatabase($task)]);
        $task->setTitle('Saved');
        $this->em->flush();
        self::assertSame([2, 2], [$task->getRevision(), $this->revisionInDatabase($task)]);
    }

    /**
     * A second writer, as another request of another process: its own manager, and its own connection unless the
     * database lives in this connection's memory.
     */
    private function otherWriter(): EntityManagerInterface
    {
        $connection = $this->em->getConnection();
        $params     = $connection->getParams();
        if (($params['memory'] ?? false) !== true && ($params['path'] ?? null) !== ':memory:') {
            $connection = $this->otherConnection = DriverManager::getConnection($params, $connection->getConfiguration());
        }

        return new EntityManager($connection, $this->em->getConfiguration(), $this->em->getEventManager());
    }

    private function revisionInDatabase(Task $task): int
    {
        return (int) $this->em->getConnection()->fetchOne('SELECT revision FROM task_board_tasks WHERE id = ?', [$task->getId()]);
    }
}
