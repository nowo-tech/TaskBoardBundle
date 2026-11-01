<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Tests\Functional\Repository;

use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Nowo\TaskBoardBundle\Entity\BoardColumn;
use Nowo\TaskBoardBundle\Entity\ColumnSet;
use Nowo\TaskBoardBundle\Entity\Task;
use Nowo\TaskBoardBundle\Entity\TaskBoard;
use Nowo\TaskBoardBundle\Entity\TaskChangeHistory;
use Nowo\TaskBoardBundle\Entity\TaskComment;
use Nowo\TaskBoardBundle\Entity\TaskDependency;
use Nowo\TaskBoardBundle\Entity\TaskLink;
use Nowo\TaskBoardBundle\Entity\TaskMember;
use Nowo\TaskBoardBundle\Entity\TaskTimeEntry;
use Nowo\TaskBoardBundle\Entity\Team;
use Nowo\TaskBoardBundle\Entity\TeamMember;
use Nowo\TaskBoardBundle\Enum\TaskChangeType;
use Nowo\TaskBoardBundle\Enum\TaskDependencyType;
use Nowo\TaskBoardBundle\Enum\TaskLinkType;
use Nowo\TaskBoardBundle\Enum\TaskMemberRole;
use Nowo\TaskBoardBundle\Enum\TeamRole;
use Nowo\TaskBoardBundle\Repository\BoardColumnRepositoryInterface;
use Nowo\TaskBoardBundle\Repository\ColumnSetRepositoryInterface;
use Nowo\TaskBoardBundle\Repository\DoctrineOrmTaskChangeHistoryRepository;
use Nowo\TaskBoardBundle\Repository\DoctrineOrmTaskDependencyRepository;
use Nowo\TaskBoardBundle\Repository\DoctrineOrmTaskDocumentRepository;
use Nowo\TaskBoardBundle\Repository\DoctrineOrmTaskLinkRepository;
use Nowo\TaskBoardBundle\Repository\DoctrineOrmTaskMemberRepository;
use Nowo\TaskBoardBundle\Repository\DoctrineOrmTaskRepository;
use Nowo\TaskBoardBundle\Repository\DoctrineOrmTaskTimeEntryRepository;
use Nowo\TaskBoardBundle\Repository\DoctrineOrmTeamRepository;
use Nowo\TaskBoardBundle\Repository\TaskBoardRepositoryInterface;
use Nowo\TaskBoardBundle\Repository\TaskCommentRepositoryInterface;
use Nowo\TaskBoardBundle\Repository\TaskRepositoryInterface;
use Nowo\TaskBoardBundle\Repository\TeamMemberRepositoryInterface;
use Nowo\TaskBoardBundle\Tests\Functional\App\Entity\AppUser;
use Nowo\TaskBoardBundle\Tests\Functional\App\FunctionalKernel;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpKernel\KernelInterface;

use function array_map;

/**
 * The bundle's Doctrine repositories against a real database (SQLite in memory, or TASK_BOARD_TEST_DATABASE_URL).
 */
final class DoctrineRepositoriesTest extends KernelTestCase
{
    private EntityManagerInterface $em;

    private AppUser $alice;

    private AppUser $bob;

    /**
     * @param array<string, mixed> $options
     */
    protected static function createKernel(array $options = []): KernelInterface
    {
        return new FunctionalKernel();
    }

    protected function setUp(): void
    {
        self::bootKernel();
        $em = self::getContainer()->get('doctrine.orm.entity_manager');
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $this->em = $em;
        $tool     = new SchemaTool($this->em);
        $tool->dropDatabase();
        $tool->createSchema($this->em->getMetadataFactory()->getAllMetadata());

        $this->alice = new AppUser('alice@example.com');
        $this->bob   = new AppUser('bob@example.com');
        $this->em->persist($this->alice);
        $this->em->persist($this->bob);
        $this->em->flush();
    }

    public function testBoardsAndColumns(): void
    {
        $boards  = $this->service(TaskBoardRepositoryInterface::class);
        $columns = $this->service(BoardColumnRepositoryInterface::class);

        $zeta  = new TaskBoard('Zeta', 'zeta', $this->alice);
        $alpha = (new TaskBoard('Alpha', 'alpha', $this->alice))->setTenantKey('acme');
        $old   = (new TaskBoard('Old', 'old', $this->alice))->setTenantKey('acme');
        $boards->save($zeta);
        $boards->save($alpha);
        $boards->save($old->archive());

        $todo = new BoardColumn($zeta, 'To do', 0);
        $done = new BoardColumn($zeta, 'Done', 1);
        $columns->save($todo);
        $columns->saveAll([$done]);
        $this->em->clear();

        self::assertSame(['Alpha', 'Zeta'], array_map(static fn (TaskBoard $b): string => $b->getName(), $boards->findAllActive()));
        self::assertSame(['Alpha'], array_map(static fn (TaskBoard $b): string => $b->getName(), $boards->findActiveByTenant('acme')));
        self::assertSame('Alpha', $boards->findOneByTenant('acme')?->getName());
        self::assertNull($boards->findOneByTenant('nobody'));
        self::assertSame('Zeta', $boards->findBySlug('zeta')?->getName());
        self::assertNull($boards->findBySlug('missing'));
        self::assertNull($boards->findById('missing'));

        $reloaded = $boards->findById($zeta->getId());
        self::assertInstanceOf(TaskBoard::class, $reloaded);
        self::assertCount(2, $reloaded->getColumns());

        $boards->remove($reloaded);
        self::assertNull($boards->findById($zeta->getId()));
    }

    public function testTasksBoundedQueriesDetailAndLookups(): void
    {
        $tasks = $this->service(TaskRepositoryInterface::class);
        self::assertInstanceOf(DoctrineOrmTaskRepository::class, $tasks);
        [$board, $column] = $this->boardWithColumn();

        $first  = new Task($board, 'First', $this->alice, $column, position: 0);
        $second = new Task($board, 'Second', $this->alice, $column, position: 1);
        $done   = new Task($board, 'Done', $this->alice, $column, position: 2);
        $done->markCompleted();
        $first->addMember(new TaskMember($first, $this->bob, TaskMemberRole::Assignee));
        $first->addLink(new TaskLink($first, TaskLinkType::HostObject, '/issues/7', 'Issue 7', objectType: 'issue', objectId: '7'));
        $first->addOutgoingDependency(new TaskDependency($first, $second, TaskDependencyType::Blocks));
        $first->addTimeEntry(new TaskTimeEntry($first, $this->bob, 30, new DateTimeImmutable('2026-10-01')));
        $first->addChangeHistory(new TaskChangeHistory($first, $this->alice, TaskChangeType::Created));
        $this->em->persist($first);
        $this->em->persist($second);
        $tasks->save($done);
        $this->em->clear();

        self::assertSame(['First', 'Second'], $this->titles($tasks->findByBoard($board)));
        self::assertSame(['First', 'Second', 'Done'], $this->titles($tasks->findByBoard($board, true)));
        self::assertSame(['First', 'Second'], $this->titles($tasks->findByBoardWithin($board, true, 2)));
        self::assertSame(['First'], $this->titles($tasks->findByBoardWithin($board, false, 1)));
        self::assertSame([], $tasks->findByBoardWithin(new TaskBoard('Empty', 'empty', $this->alice), true, 10));
        self::assertSame(3, $tasks->countByBoard($board, true));
        self::assertSame(2, $tasks->countByBoard($board));

        $detail = $tasks->findForDetail($first->getId());
        self::assertInstanceOf(Task::class, $detail);
        self::assertCount(1, $detail->getMembers());
        self::assertCount(1, $detail->getLinks());
        self::assertCount(1, $detail->getOutgoingDependencies());
        self::assertCount(1, $detail->getTimeEntries());
        self::assertNull($tasks->findForDetail('missing'));
        self::assertNull($tasks->findById('missing'));

        self::assertSame(['First'], $this->titles($tasks->findByHostObject('issue', '7')));
        self::assertSame(['First'], $this->titles($tasks->findTrackableForUser((string) $this->bob->getId(), 'fir', 10, 0)));
        self::assertSame([], $tasks->findTrackableForUser((string) $this->bob->getId(), 'nothing', 10, 0));
        self::assertSame(['First'], $this->titles($tasks->findTrackableForUser((string) $this->bob->getId(), null, 0, -1)));
    }

    public function testCommentsColumnSetsAndTeams(): void
    {
        [$board, $column] = $this->boardWithColumn();
        $task             = new Task($board, 'Discussed', $this->alice, $column);
        $this->service(TaskRepositoryInterface::class)->save($task);

        $comments = $this->service(TaskCommentRepositoryInterface::class);
        $kept     = new TaskComment($task, $this->alice, 'First');
        $deleted  = new TaskComment($task, $this->bob, 'Gone');
        $comments->save($kept);
        $comments->save($deleted->markDeleted());
        $this->em->clear();

        // A deleted comment keeps its place in the stream, without its body.
        self::assertSame(['First', ''], array_map(static fn (TaskComment $c): string => $c->getBody(), $comments->findByTask($this->reload(Task::class, $task->getId()))));
        self::assertSame([$task->getId() => 1], $comments->countByTasks([$task->getId()]));
        self::assertSame([], $comments->countByTasks([]));
        self::assertSame('First', $comments->findById($kept->getId())?->getBody());

        // The users were detached by clear().
        $this->alice = $this->reload(AppUser::class, (int) $this->alice->getId());
        $this->bob   = $this->reload(AppUser::class, (int) $this->bob->getId());

        $sets   = $this->service(ColumnSetRepositoryInterface::class);
        $global = new ColumnSet('Global', $this->alice);
        $scoped = new ColumnSet('Scoped', $this->alice, 'acme');
        $sets->save($global);
        $sets->save($scoped);
        self::assertSame(['Global'], array_map(static fn (ColumnSet $s): string => $s->getName(), $sets->findByScope(null)));
        self::assertSame(['Scoped'], array_map(static fn (ColumnSet $s): string => $s->getName(), $sets->findByScope('acme')));
        self::assertSame('Scoped', $sets->findById($scoped->getId())?->getName());
        $sets->remove($scoped);
        self::assertNull($sets->findById($scoped->getId()));

        $teams = new DoctrineOrmTeamRepository($this->em);
        $team  = new Team('Core');
        $teams->save($team);
        $other = new Team('Other');
        $teams->save($other);
        $this->em->persist(new TeamMember($team, $this->alice, TeamRole::Manager));
        $this->em->persist(new TeamMember($team, $this->bob, TeamRole::Member));
        $this->em->persist(new TeamMember($other, $this->bob, TeamRole::Member));
        $this->em->flush();

        $members = $this->service(TeamMemberRepositoryInterface::class);
        self::assertCount(2, $members->findByUserId((string) $this->bob->getId()));
        self::assertCount(2, $members->findMembersManagedBy((string) $this->alice->getId()));
        self::assertSame([], $members->findMembersManagedBy((string) $this->bob->getId()));
        self::assertTrue($members->isManagerOf((string) $this->alice->getId(), (string) $this->bob->getId()));
        self::assertFalse($members->isManagerOf((string) $this->alice->getId(), 'nobody'));
    }

    public function testChangeHistoryAndTheEntityManagerHolders(): void
    {
        [$board, $column] = $this->boardWithColumn();
        $task             = new Task($board, 'Tracked', $this->alice, $column);
        $this->service(TaskRepositoryInterface::class)->save($task);

        $history = new DoctrineOrmTaskChangeHistoryRepository($this->em);
        $history->save(new TaskChangeHistory($task, $this->alice, TaskChangeType::Created));
        $history->save(new TaskChangeHistory($task, $this->alice, TaskChangeType::Title, 'Tracked', 'Tracked!'));
        self::assertCount(2, $history->findByTask($task));

        // Persistence goes through the task aggregate: these repositories only hold the entity manager.
        foreach ([
            new DoctrineOrmTaskDependencyRepository($this->em),
            new DoctrineOrmTaskDocumentRepository($this->em),
            new DoctrineOrmTaskLinkRepository($this->em),
            new DoctrineOrmTaskMemberRepository($this->em),
            new DoctrineOrmTaskTimeEntryRepository($this->em),
        ] as $repository) {
            self::assertSame($this->em, $repository->getEntityManager());
        }
    }

    /**
     * @template T of object
     *
     * @param class-string<T> $id
     *
     * @return T
     */
    private function service(string $id): object
    {
        $service = self::getContainer()->get($id);
        self::assertInstanceOf($id, $service);

        return $service;
    }

    /**
     * @template T of object
     *
     * @param class-string<T> $class
     *
     * @return T
     */
    private function reload(string $class, string|int $id): object
    {
        $entity = $this->em->find($class, $id);
        self::assertInstanceOf($class, $entity);

        return $entity;
    }

    /**
     * @return array{0: TaskBoard, 1: BoardColumn}
     */
    private function boardWithColumn(): array
    {
        $board  = new TaskBoard('Board', 'board', $this->alice);
        $column = new BoardColumn($board, 'To do', 0);
        $board->addColumn($column);
        $this->service(TaskBoardRepositoryInterface::class)->save($board);

        return [$board, $column];
    }

    /**
     * @param list<Task> $tasks
     *
     * @return list<string>
     */
    private function titles(array $tasks): array
    {
        return array_map(static fn (Task $task): string => $task->getTitle(), $tasks);
    }
}
