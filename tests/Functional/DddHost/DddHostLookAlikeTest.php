<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Tests\Functional\DddHost;

use DateTimeImmutable;
use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use Doctrine\ORM\Tools\SchemaTool;
use Nowo\TaskBoardBundle\Api\Hook\TaskApiTimeEntriesInterface;
use Nowo\TaskBoardBundle\Api\Hook\TaskApiTimeEntryBillableInterface;
use Nowo\TaskBoardBundle\Api\Repository\TaskApiQueryRepository;
use Nowo\TaskBoardBundle\Api\TaskApiLevel;
use Nowo\TaskBoardBundle\Api\TaskApiPrincipal;
use Nowo\TaskBoardBundle\Api\TaskApiRefusal;
use Nowo\TaskBoardBundle\Api\TaskApiWhere;
use Nowo\TaskBoardBundle\Api\Value\TaskApiTaskFilter;
use Nowo\TaskBoardBundle\Api\Value\TaskApiTimeQuery;
use Nowo\TaskBoardBundle\Doctrine\TaskBoardEntityManager;
use Nowo\TaskBoardBundle\Doctrine\Type\TaskBoardUserRefType;
use Nowo\TaskBoardBundle\Dto\TaskBoardFormData;
use Nowo\TaskBoardBundle\Dto\TaskFormData;
use Nowo\TaskBoardBundle\Entity\TaskMember;
use Nowo\TaskBoardBundle\Entity\TaskTimeEntry;
use Nowo\TaskBoardBundle\Enum\TaskMemberRole;
use Nowo\TaskBoardBundle\MultiBoard\DoctrineMultiBoardTaskQuery;
use Nowo\TaskBoardBundle\MultiBoard\MultiBoardTaskFilter;
use Nowo\TaskBoardBundle\Repository\TaskRepositoryInterface;
use Nowo\TaskBoardBundle\Service\TaskBoardCreatorInterface;
use Nowo\TaskBoardBundle\Service\TaskManagerInterface;
use Nowo\TaskBoardBundle\Service\TaskTimeLoggerInterface;
use Nowo\TaskBoardBundle\Sync\People\TaskSyncPeopleReassigner;
use Nowo\TaskBoardBundle\Sync\People\TaskSyncPersonRepository;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpKernel\KernelInterface;

/**
 * Audit OC-M2: every lookup and erasure by user id keeps only the rows whose id is equal byte for byte in PHP. The id
 * columns are created here with a case-insensitive collation (SQLite NOCASE, MySQL / MariaDB utf8mb4_general_ci, which
 * is PAD SPACE too), as a `*_ci` or PAD SPACE column would compare: the database hands back Bruno's rows for "U-BRUNO", and the bundle must drop them.
 */
final class DddHostLookAlikeTest extends KernelTestCase
{
    /**
     * @param array<string, mixed> $options
     */
    protected static function createKernel(array $options = []): KernelInterface
    {
        return new DddHostKernel([
            'api'  => ['enabled' => true, 'hooks' => ['authenticator' => 'app.users', 'time_entries' => 'Nowo\\TaskBoardBundle\\Api\\Hook\\ManualTaskApiTimeEntries', 'users' => 'app.users']],
            'sync' => ['enabled' => true, 'credential_resolver' => 'app.users'],
        ]);
    }

    public function testLookAlikeRowsTheDatabaseReturnsAreDropped(): void
    {
        self::bootKernel();
        $container = self::getContainer();
        $em        = $container->get('app.task_board_em');
        self::assertInstanceOf(TaskBoardEntityManager::class, $em);
        $platform  = $em->getConnection()->getDatabasePlatform();
        $collation = match (true) {
            $platform instanceof SQLitePlatform        => 'NOCASE',
            $platform instanceof AbstractMySQLPlatform => 'utf8mb4_general_ci', // case-insensitive and PAD SPACE
            default                                    => null,
        };
        if ($collation === null) {
            self::markTestSkipped('PostgreSQL has no built-in case-insensitive collation; its columns compare exactly.');
        }
        $metadata = $em->getMetadataFactory()->getAllMetadata();
        foreach ($metadata as $class) {
            foreach ($class->fieldMappings as $field) {
                if ($field->type === TaskBoardUserRefType::NAME) {
                    $field->options = ['collation' => $collation];
                }
            }
        }
        $tool = new SchemaTool($em);
        $tool->dropDatabase();
        $tool->createSchema($metadata);

        $users = DddUsers::all();
        $bruno = $users['u-bruno'];
        $upper = new DddUser('U-BRUNO', 'Upper Bruno', 'upper-bruno@example.com');
        $board = $container->get(TaskBoardCreatorInterface::class)->create(new TaskBoardFormData('Look-alikes', 'look-alikes'), $bruno);
        $task  = $container->get(TaskManagerInterface::class)->create($board, new TaskFormData('Bruno task'), $bruno);
        $entry = $container->get(TaskTimeLoggerInterface::class)->log($task, $bruno, 30, new DateTimeImmutable('2026-11-12'));
        $task->addMember(new TaskMember($task, $bruno, TaskMemberRole::Assignee));
        $em->flush();
        $entryId = $entry->getId();
        $em->clear();

        $repository = $container->get(TaskRepositoryInterface::class);
        self::assertInstanceOf(TaskRepositoryInterface::class, $repository);
        self::assertCount(1, $repository->findTrackableForUser('u-bruno', null, 10, 0));
        self::assertSame([], $repository->findTrackableForUser('U-BRUNO', null, 10, 0));

        $time = $container->get(TaskApiTimeEntriesInterface::class);
        self::assertInstanceOf(TaskApiTimeEntryBillableInterface::class, $time);
        $principal = static fn (object $user): TaskApiPrincipal => new TaskApiPrincipal($user, TaskApiLevel::Full, TaskApiWhere::everything(), 'token', 'Token');
        self::assertSame([], $time->list($principal($upper), new TaskApiTimeQuery('t'))->entries);
        try {
            $time->setBillable($principal($upper), $entryId, true);
            self::fail('not the look-alike\'s entry');
        } catch (TaskApiRefusal) {
        }

        $query = $container->get(TaskApiQueryRepository::class);
        self::assertInstanceOf(TaskApiQueryRepository::class, $query);
        self::assertNotSame([], $query->taskPage([$board->getId()], new TaskApiTaskFilter(), [], $bruno)['tasks']);
        self::assertSame([], $query->taskPage([$board->getId()], new TaskApiTaskFilter(), [], $upper)['tasks']);
        self::assertSame([], $query->taskPage([$board->getId()], new TaskApiTaskFilter(assigneeIds: ['x']), [$upper], null)['tasks']);
        self::assertCount(1, $query->taskPage([$board->getId()], new TaskApiTaskFilter(assigneeIds: ['x']), [$bruno], null)['tasks']);
        // Since 2.15.1: an id that cannot be matched exactly finds nothing, as assignee or as owner.
        $unusable = new DddUser("u-bruno\u{200B}", 'ZWSP Bruno', 'zwsp-bruno@example.com');
        self::assertSame([], $query->taskPage([$board->getId()], new TaskApiTaskFilter(assigneeIds: ['x']), [$unusable], null)['tasks']);
        self::assertSame([], $query->taskPage([$board->getId()], new TaskApiTaskFilter(), [], $unusable)['tasks']);

        $multi = $container->get(DoctrineMultiBoardTaskQuery::class);
        self::assertInstanceOf(DoctrineMultiBoardTaskQuery::class, $multi);
        self::assertSame([], $multi->workload([$board->getId()], new MultiBoardTaskFilter(assigneeId: 'U-BRUNO'), new DateTimeImmutable()));
        self::assertCount(1, $multi->workload([$board->getId()], new MultiBoardTaskFilter(assigneeId: 'u-bruno'), new DateTimeImmutable()));

        $people = $container->get(TaskSyncPersonRepository::class);
        self::assertInstanceOf(TaskSyncPersonRepository::class, $people);
        self::assertSame([], $people->linkedToUser($upper));

        $reassigner = $container->get(TaskSyncPeopleReassigner::class);
        self::assertInstanceOf(TaskSyncPeopleReassigner::class, $reassigner);
        $reassigner->detachUser($upper, $bruno);
        $em->clear();
        $table = $em->getClassMetadata(TaskTimeEntry::class)->getTableName();
        self::assertSame('u-bruno', $em->getConnection()->fetchOne('SELECT user_id FROM ' . $table . ' WHERE id = ?', [$entryId]), 'Bruno keeps his rows');
        self::assertSame('u-bruno', $repository->findById($task->getId())?->getCreator()?->getId());
    }
}
