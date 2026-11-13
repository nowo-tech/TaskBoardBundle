<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Tests\Integration\PrivateEntityManager;

use Doctrine\DBAL\Driver;
use Doctrine\DBAL\Driver\Connection as DriverConnection;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use Doctrine\ORM\Tools\SchemaTool;
use Doctrine\Persistence\ManagerRegistry;
use Doctrine\Persistence\ObjectManager;
use LogicException;
use Nowo\TaskBoardBundle\DependencyInjection\Compiler\PrivateEntityManagerPass;
use Nowo\TaskBoardBundle\DependencyInjection\Compiler\PrivateEntityManagerReferencesPass;
use Nowo\TaskBoardBundle\Doctrine\PrivateEntityManagerConnection;
use Nowo\TaskBoardBundle\Doctrine\PrivateEntityManagerMiddleware;
use Nowo\TaskBoardBundle\Doctrine\TaskBoardEntityManager;
use Nowo\TaskBoardBundle\Doctrine\TaskBoardManagerRegistry;
use Nowo\TaskBoardBundle\Doctrine\TaskBoardSavepoint;
use Nowo\TaskBoardBundle\Entity\TaskBoard;
use Nowo\TaskBoardBundle\Entity\Team;
use Nowo\TaskBoardBundle\Tests\Functional\App\Entity\AppUser;
use Nowo\TaskBoardBundle\Tests\Functional\App\FunctionalKernel;
use Nowo\TaskBoardBundle\Tests\Functional\App\RecordingLogger;
use PHPUnit\Framework\Attributes\Test;
use Psr\Log\AbstractLogger;
use ReflectionProperty;
use RuntimeException;
use Stringable;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Reference;
use Symfony\Component\HttpKernel\KernelInterface;

use function array_filter;
use function array_values;
use function assert;
use function bin2hex;
use function json_encode;
use function random_bytes;

/**
 * Since 2.15.0: the private entity manager parts (adapted from AuthKitBundle 1.46.0 / VaultBundle 1.19.0, audits NJ-2,
 * NJV-1, NF-2, NF2-2, NF3-2, NF-7, NZ): the rollback middleware, the replacement rules, the boundary drop that cannot be
 * poisoned, the registry the TaskBoard services see, the repositories, the savepoint helper and the build pass.
 */
final class PrivateEntityManagerPartsTest extends KernelTestCase
{
    /**
     * @param array<string, mixed> $options
     */
    protected static function createKernel(array $options = []): KernelInterface
    {
        return new FunctionalKernel(['database' => ['private_entity_manager' => true]]);
    }

    #[Test]
    public function onlyARollbackOfRowsThePrivateManagerWroteMakesItStale(): void
    {
        $private = $this->privateManager();
        $real    = $private->getConnection();
        $inner   = $this->createMock(DriverConnection::class);
        $inner->method('exec')->willReturn(0);
        $inner->method('commit')->willReturnOnConsecutiveCalls(null, self::throwException(new RuntimeException('commit failed')));
        $connection = new PrivateEntityManagerConnection($inner, $private);
        $real->beginTransaction();

        $this->write($private);
        $connection->exec('RELEASE SAVEPOINT DOCTRINE_2');
        $connection->exec('ROLLBACK TO SAVEPOINT nowo_task_board_0a1b2c');
        $connection->exec('ROLLBACK TO SAVEPOINT DOCTRINE_2');
        self::assertFalse($private->isStale(), 'NJ-2: a TaskBoard savepoint or a deeper level the manager did not write in');
        $connection->exec('ROLLBACK TO SAVEPOINT host_point');
        self::assertTrue($private->isStale(), 'a savepoint of unknown level: conservative');

        $private->reset();
        $this->write($private);
        $connection->rollBack();
        self::assertTrue($private->isStale());

        $private->reset();
        $this->write($private);
        $connection->commit();
        $connection->rollBack();
        self::assertFalse($private->isStale(), 'committed rows are not undone by a later rollback');

        $private->reset();
        $this->write($private);
        try {
            $connection->commit();
            self::fail('the commit failure is rethrown');
        } catch (RuntimeException) {
        }
        self::assertTrue($private->isStale());

        // Rows written in a nested level move down when its savepoint is released.
        $private->reset();
        $real->beginTransaction();
        $this->write($private);
        $connection->exec('RELEASE SAVEPOINT DOCTRINE_2');
        $connection->exec('ROLLBACK TO SAVEPOINT DOCTRINE_2');
        self::assertFalse($private->isStale());
        $connection->rollBack();
        self::assertTrue($private->isStale());
        $real->rollBack();
        $real->rollBack();

        $driver = $this->createMock(Driver::class);
        $driver->method('connect')->willReturn($this->createMock(DriverConnection::class));
        self::assertInstanceOf(PrivateEntityManagerConnection::class, (new PrivateEntityManagerMiddleware($private))->wrap($driver)->connect([]));
    }

    #[Test]
    public function aStaleManagerWithPendingWorkIsKeptUntilFlushed(): void
    {
        $private = $this->privateManager();
        $real    = $private->getConnection();
        $real->beginTransaction();
        $this->write($private);
        $live = $private->live();
        $private->persist($this->board('pending'));
        $private->connectionRolledBack(1);

        self::assertSame($live, $private->live(), 'pending inserts survive a rollback signal');
        $private->flush();
        $private->connectionRolledBack(1);
        self::assertNotSame($live, $private->live(), 'no pending work: replaced');
        $private->connectionRolledBack(null);
        self::assertFalse($private->isStale(), 'a fresh manager wrote nothing');
        $private->wrapInTransaction(static fn (): bool => true);
        $real->rollBack();
        $private->clear();
        (new TaskBoardEntityManager($this->doctrine()))->clear();
    }

    #[Test]
    public function aClosedManagerIsReplacedAndLoadsAndRefreshesAreTracked(): void
    {
        $this->doctrine();
        $private = self::getContainer()->get('app.task_board_em');
        self::assertInstanceOf(TaskBoardEntityManager::class, $private);
        $live = $private->live();
        $live->close();
        self::assertNotSame($live, $private->live(), 'a closed manager is created again');

        $board = $this->board('loaded');
        $private->persist($board);
        $private->flush();
        $private->clear();
        $real = $private->getConnection();
        $real->beginTransaction();
        $loaded = $private->find(TaskBoard::class, $board->getId());
        self::assertInstanceOf(TaskBoard::class, $loaded);
        $private->refresh($loaded);
        $private->getConnection()->rollBack();
        self::assertTrue($private->isStale(), 'NJV-1: a load inside a rolled-back transaction makes the identity map stale');
    }

    private function write(TaskBoardEntityManager $private): void
    {
        $private->persist($this->board(bin2hex(random_bytes(4))));
        $private->flush();
    }

    private function board(string $slug, ?string $tenantKey = null): TaskBoard
    {
        $board = new TaskBoard('Board ' . $slug, $slug, null);
        $board->setTenantKey($tenantKey);

        return $board;
    }

    #[Test]
    public function aBoundaryThatCannotInspectOrClearStillDropsTheManager(): void
    {
        $logger  = new RecordingLogger();
        $private = new TaskBoardEntityManager($this->doctrine(), 'default', $logger);
        $failing = $this->createMock(EntityManagerInterface::class);
        $failing->method('isOpen')->willReturn(true);
        $failing->method('getUnitOfWork')->willThrowException(new RuntimeException('change sets'));
        $failing->method('clear')->willThrowException(new RuntimeException('onClear listener'));
        (new ReflectionProperty(TaskBoardEntityManager::class, 'current'))->setValue($private, $failing);

        $private->reset();

        self::assertFalse($private->isCreated());
        self::assertCount(2, $this->warnings($logger), 'inspection and clear failures are logged, never rethrown');

        $quiet = new TaskBoardEntityManager($this->doctrine(), 'default', new class extends AbstractLogger {
            public function log(mixed $level, string|Stringable $message, array $context = []): void
            {
                throw new RuntimeException('logger down');
            }
        });
        (new ReflectionProperty(TaskBoardEntityManager::class, 'current'))->setValue($quiet, $failing);
        $quiet->reset();
        self::assertFalse($quiet->isCreated());

        $closed = new TaskBoardEntityManager($this->doctrine());
        $closed->live()->close();
        $closed->reset();
        self::assertFalse($closed->isCreated());
    }

    #[Test]
    public function aBoundaryLogsTheDroppedChangesByClassOnly(): void
    {
        $logger  = new RecordingLogger();
        $private = new TaskBoardEntityManager($this->doctrine(), 'default', $logger);
        $private->persist($this->board('dropped-secret-slug'));
        $private->reset();

        $records = $this->warnings($logger);
        self::assertCount(1, $records);
        self::assertSame([TaskBoard::class => 1], $records[0]['context']['changes']);
        self::assertStringNotContainsString('dropped-secret-slug', (string) json_encode($records));

        $silent = new TaskBoardEntityManager($this->doctrine());
        $silent->persist($this->board('silent'));
        $silent->reset();
        self::assertFalse($silent->isCreated(), 'without a logger the changes are dropped silently');
        $nothing = new TaskBoardEntityManager($this->doctrine(), 'default', $logger);
        $nothing->live();
        $nothing->reset();
        self::assertCount(1, $logger->records, 'nothing pending: nothing logged');
    }

    #[Test]
    public function theSourceMustBeAnOrmManagerThatMapsTheTaskBoardEntities(): void
    {
        $doctrine = $this->createMock(ManagerRegistry::class);
        $doctrine->method('getManager')->willReturn($this->createMock(ObjectManager::class));
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('does not map');
        (new TaskBoardEntityManager($doctrine, 'other'))->source();
    }

    #[Test]
    public function theSourceSqlFiltersAreMirroredWithTheirParameters(): void
    {
        $doctrine = $this->doctrine();
        $source   = $doctrine->getManager();
        assert($source instanceof EntityManagerInterface);
        $private = new TaskBoardEntityManager($doctrine);
        $private->persist($this->board('one', 'acme'));
        $private->persist($this->board('two', 'other'));
        $private->flush();
        $source->getConfiguration()->addFilter('test_board_tenant', BoardTenantFilter::class);

        $source->getFilters()->enable('test_board_tenant')->setParameter('tenant', 'acme');
        self::assertSame(['one'], $this->slugs($private));
        $copy = $private->live()->getFilters()->getFilter('test_board_tenant');
        self::assertSame($copy, $private->live()->getFilters()->getFilter('test_board_tenant'), 'an unchanged filter is kept');
        $source->getFilters()->getFilter('test_board_tenant')->setParameter('tenant', 'other');
        self::assertSame(['two'], $this->slugs($private), 'a changed parameter is copied');
        $source->getFilters()->getFilter('test_board_tenant')->setParameterList('tenants', ['acme', 'other'], 'string');
        self::assertSame(['two'], $this->slugs($private), 'a list parameter keeps its type');
        $source->getFilters()->disable('test_board_tenant');
        self::assertSame(['one', 'two'], $this->slugs($private), 'a filter the source disabled is disabled');
    }

    /**
     * @return list<string>
     */
    private function slugs(TaskBoardEntityManager $private): array
    {
        $private->clear();
        $slugs = [];
        /** @var list<TaskBoard> $boards */
        $boards = $private->createQueryBuilder()->select('b')->from(TaskBoard::class, 'b')->orderBy('b.slug')->getQuery()->getResult();
        foreach ($boards as $board) {
            $slugs[] = $board->getSlug();
        }

        return $slugs;
    }

    /**
     * @return list<array{level: string, message: string, context: array<string, mixed>}>
     */
    private function warnings(RecordingLogger $logger): array
    {
        return array_values(array_filter($logger->records, static fn (array $record): bool => $record['level'] === 'warning'));
    }

    #[Test]
    public function repositoriesAreBoundToTheDecoratorUntilTheNextBoundary(): void
    {
        $private    = $this->privateManager();
        $repository = $private->getRepository(TaskBoard::class);
        self::assertSame($repository, $private->getRepository(TaskBoard::class));
        $private->connectionRolledBack(null);
        self::assertSame($repository, $private->getRepository(TaskBoard::class), 'kept while the manager lives');
        $private->reset();
        self::assertNotSame($repository, $private->getRepository(TaskBoard::class), 'emptied with the manager at a boundary');
        self::assertInstanceOf(EntityRepository::class, $private->getRepository(Team::class));
        self::assertSame($private->live(), $private->getRepository(TaskBoard::class)->createQueryBuilder('b')->getEntityManager()->getConnection() === $private->getConnection() ? $private->live() : null);
    }

    #[Test]
    public function aServiceRepositoryIsAskedOfTheSourceFactory(): void
    {
        $private  = $this->privateManager();
        $metadata = $private->live()->getClassMetadata(AppUser::class);
        $before   = $metadata->customRepositoryClassName;
        // A host entity whose repository is a ServiceEntityRepository: DefaultRepositoryFactory could not build it (its
        // constructor takes a registry); the source factory (DoctrineBundle's container factory) is asked instead.
        $metadata->customRepositoryClassName = HostServiceRepository::class;
        try {
            $private->getRepository(AppUser::class);
            self::fail('the container factory is asked for the service');
        } catch (RuntimeException $exception) {
            self::assertStringContainsString(HostServiceRepository::class, $exception->getMessage());
        } finally {
            $metadata->customRepositoryClassName = $before;
        }
    }

    #[Test]
    public function undefinedMagicPropertiesFail(): void
    {
        $this->expectException(LogicException::class);
        $this->privateManager()->__get('other');
    }

    #[Test]
    public function theRegistryServesThePrivateManagerAndDelegatesTheRest(): void
    {
        $doctrine = $this->doctrine();
        $private  = new TaskBoardEntityManager($doctrine);
        $registry = new TaskBoardManagerRegistry($doctrine, $private, 'default');

        self::assertSame($private, $registry->getManager());
        self::assertSame($private, $registry->getManager('default'));
        self::assertSame($doctrine->getManagers(), $registry->getManagers());
        self::assertSame($doctrine->getManagerNames(), $registry->getManagerNames());
        self::assertSame($doctrine->getDefaultManagerName(), $registry->getDefaultManagerName());
        self::assertSame($doctrine->getDefaultConnectionName(), $registry->getDefaultConnectionName());
        self::assertSame($doctrine->getConnection(), $registry->getConnection());
        self::assertSame($doctrine->getConnections(), $registry->getConnections());
        self::assertSame($doctrine->getConnectionNames(), $registry->getConnectionNames());
        self::assertSame($doctrine->getManagerForClass(AppUser::class), $registry->getManagerForClass(AppUser::class));
        self::assertSame($private, $registry->getManagerForClass($this->proxyClass()));
        self::assertSame($private->getRepository(TaskBoard::class), $registry->getRepository(TaskBoard::class));
        self::assertSame($doctrine->getRepository(AppUser::class), $registry->getRepository(AppUser::class));
        $private->live();
        self::assertSame($private, $registry->resetManager());
        self::assertFalse($private->isCreated(), 'resetting the source name drops the private manager');

        $other = new TaskBoardManagerRegistry($doctrine, $private, 'tasks');
        self::assertSame($doctrine->getManager(), $other->getManager(), 'another name: the application manager');
        self::assertSame($doctrine->getManager('default'), $other->resetManager('default'));
    }

    #[Test]
    public function aTaskBoardWriteInsideATransactionRunsInASavepointWithThePrivateManagerOnly(): void
    {
        $private    = $this->privateManager();
        $host       = $private->source();
        $connection = $private->getConnection();
        self::assertSame(1, TaskBoardSavepoint::write($private, static fn (): int => 1), 'outside a transaction: as is');

        $connection->beginTransaction();
        try {
            TaskBoardSavepoint::write($private, static function (): never {
                throw new RuntimeException('failing statement');
            });
            self::fail('the statement failure is rethrown');
        } catch (RuntimeException $exception) {
            self::assertSame('failing statement', $exception->getMessage());
        }
        self::assertTrue($connection->isTransactionActive(), 'the host transaction is untouched');
        self::assertSame(2, TaskBoardSavepoint::write($private, static fn (): int => 2));
        self::assertSame(1, $connection->getTransactionNestingLevel());

        // A rollback to the savepoint that fails too: the ORIGINAL failure is what the caller sees.
        try {
            TaskBoardSavepoint::write($private, static function () use ($connection): never {
                $connection->rollBack(); // the server ended the transaction: the savepoint is gone
                $connection->beginTransaction();

                throw new RuntimeException('original failure');
            });
            self::fail('the statement failure is rethrown');
        } catch (RuntimeException $exception) {
            self::assertSame('original failure', $exception->getMessage());
        }

        // The application's manager (default mode): exactly the statement, no savepoint.
        $levels = [];
        TaskBoardSavepoint::write($host, static function () use ($connection, &$levels): int {
            $levels[] = $connection->getTransactionNestingLevel();

            return 3;
        });
        self::assertSame([1], $levels);
        $connection->rollBack();
    }

    #[Test]
    public function theBuildPassWrapsOnlyTheSourceManagersConnection(): void
    {
        $container = new ContainerBuilder();
        $container->setParameter(PrivateEntityManagerReferencesPass::SOURCE_PARAMETER, 'main');
        $container->setDefinition(PrivateEntityManagerMiddleware::class, (new Definition(PrivateEntityManagerMiddleware::class))->addTag('doctrine.middleware'));
        $container->setDefinition('doctrine.orm.main_entity_manager', new Definition(EntityManagerInterface::class, [new Reference('doctrine.dbal.tasks_connection')]));
        (new PrivateEntityManagerPass())->process($container);
        self::assertSame([['connection' => 'tasks']], $container->getDefinition(PrivateEntityManagerMiddleware::class)->getTag('doctrine.middleware'));

        $container->removeDefinition('doctrine.orm.main_entity_manager');
        $container->setParameter('doctrine.default_connection', 'primary');
        (new PrivateEntityManagerPass())->process($container);
        self::assertSame([['connection' => 'primary']], $container->getDefinition(PrivateEntityManagerMiddleware::class)->getTag('doctrine.middleware'));

        $bare = new ContainerBuilder();
        $bare->setParameter(PrivateEntityManagerReferencesPass::SOURCE_PARAMETER, 'default');
        $bare->setDefinition(PrivateEntityManagerMiddleware::class, (new Definition(PrivateEntityManagerMiddleware::class))->addTag('doctrine.middleware'));
        (new PrivateEntityManagerPass())->process($bare);
        self::assertSame([['connection' => 'default']], $bare->getDefinition(PrivateEntityManagerMiddleware::class)->getTag('doctrine.middleware'));

        $off = new ContainerBuilder();
        (new PrivateEntityManagerPass())->process($off);
        self::assertFalse($off->hasDefinition(PrivateEntityManagerMiddleware::class), 'option off (or DBAL 3): no middleware to attach');
        self::assertTrue(PrivateEntityManagerPass::supportsRollbackMiddleware());
    }

    private function doctrine(): ManagerRegistry
    {
        self::bootKernel();
        $doctrine = self::getContainer()->get('doctrine');
        assert($doctrine instanceof ManagerRegistry);
        $em = $doctrine->getManager();
        assert($em instanceof EntityManagerInterface);
        $schema   = new SchemaTool($em);
        $metadata = $em->getMetadataFactory()->getAllMetadata();
        $schema->dropSchema($metadata);
        $schema->createSchema($metadata);

        return $doctrine;
    }

    private function privateManager(): TaskBoardEntityManager
    {
        $private = new TaskBoardEntityManager($this->doctrine());
        $private->live();

        return $private;
    }

    /**
     * @return class-string a Doctrine proxy class name of a TaskBoard entity
     */
    private function proxyClass(): string
    {
        return 'Proxies\\__CG__\\' . TaskBoard::class; // @phpstan-ignore return.type (no such class is loaded: the registry only reads the name)
    }
}
