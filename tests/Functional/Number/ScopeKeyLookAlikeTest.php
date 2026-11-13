<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Tests\Functional\Number;

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use Doctrine\ORM\Tools\SchemaTool;
use Nowo\TaskBoardBundle\Doctrine\KeyCollation;
use Nowo\TaskBoardBundle\Doctrine\TaskBoardMetadataListener;
use Nowo\TaskBoardBundle\Entity\ColumnSet;
use Nowo\TaskBoardBundle\Entity\TaskBoard;
use Nowo\TaskBoardBundle\Entity\TaskLink;
use Nowo\TaskBoardBundle\Enum\TaskLinkType;
use Nowo\TaskBoardBundle\Import\DoctrineTaskImportLinkLookup;
use Nowo\TaskBoardBundle\Number\Entity\TaskNumberCounter;
use Nowo\TaskBoardBundle\Number\Entity\TaskNumberEntry;
use Nowo\TaskBoardBundle\Number\TaskNumberReserver;
use Nowo\TaskBoardBundle\Repository\DoctrineOrmColumnSetRepository;
use Nowo\TaskBoardBundle\Repository\DoctrineOrmTaskBoardRepository;
use RuntimeException;

use function array_map;
use function sprintf;

/**
 * REQ-SEC-006 (audit F5): the tenant key, the board slug, the import external id and the task-number scope are matched
 * byte for byte. `Bob`, `bób`, `bob `, `bob\n`, NBSP and ZWSP variants never resolve to `bob`.
 *
 * The first test runs on the bundle's own mapping (the NO PAD binary collation on MySQL / MariaDB, SQLite and PostgreSQL
 * compare exactly): look-alike tenants live side by side, each with its own slugs and number sequence. The second one
 * recreates the key columns with a folding collation (SQLite NOCASE; MySQL / MariaDB `utf8mb4_general_ci`, which folds
 * case and accents and is PAD SPACE), as a database not yet altered by the UPGRADING step compares: the database hands
 * back the look-alikes' rows and the bundle must drop them.
 */
final class ScopeKeyLookAlikeTest extends TaskNumberTestCase
{
    /** The look-alikes of `bob`; the last four cannot even be set as a key (REQ-SEC-006 item 1). */
    private const LOOK_ALIKES = ['Bob', 'bób', 'bob ', "bob\n", "bob\u{00A0}", "bob\u{200B}"];

    public function testTheKeyColumnsKeepLookAlikeTenantsApart(): void
    {
        $platform = $this->em->getConnection()->getDatabasePlatform();
        if ($platform instanceof AbstractMySQLPlatform) {
            $table  = $this->em->getClassMetadata(TaskBoard::class)->getTableName();
            $column = $this->em->getConnection()->fetchAssociative(sprintf("SHOW FULL COLUMNS FROM %s WHERE Field = 'tenant_key'", $table));
            self::assertIsArray($column);
            self::assertContains($column['Collation'], [KeyCollation::MYSQL_NOPAD, KeyCollation::MARIADB_NOPAD], 'the bundle maps a binary NO PAD collation');
        }

        $boards = [];
        foreach (['bob', 'Bob', 'bób'] as $key) {
            $board = $this->board('Same', tenantKey: $key);
            $board->setSlug('same');
            $this->em->flush(); // the unique (tenant_key, slug) key no longer folds
            $boards[$key] = $board;
            self::assertSame(1, $this->task($board, 'first of ' . $key)->getNumber(), 'each tenant has its own sequence');
        }
        // Rows a host wrote before 2.15.1, with keys the bundle now refuses: still apart, never reached by `bob`.
        foreach (['bob ', "bob\n", "bob\u{00A0}", "bob\u{200B}"] as $legacy) {
            $board = $this->board('Legacy', tenantKey: 'placeholder');
            $board->setSlug('same');
            $this->em->flush();
            $this->em->getConnection()->update($this->em->getClassMetadata(TaskBoard::class)->getTableName(), ['tenant_key' => $legacy], ['id' => $board->getId()]);
        }
        $this->em->clear();

        $repository = new DoctrineOrmTaskBoardRepository($this->em);
        foreach (['bob', 'Bob', 'bób'] as $key) {
            self::assertSame([$boards[$key]->getId()], array_map(static fn (TaskBoard $board): string => $board->getId(), $repository->findActiveByTenant($key)), $key);
            self::assertSame($boards[$key]->getId(), $repository->findOneByTenant($key)?->getId(), $key);
            self::assertSame($boards[$key]->getId(), $repository->findBySlugInTenant('same', $key)?->getId(), $key);
            self::assertSame('first of ' . $key, $this->ledger()->find($key, 1)?->getTitle());
        }
        foreach (['bob ', "bob\n", "bob\u{00A0}", "bob\u{200B}"] as $refused) {
            self::assertSame([], $repository->findActiveByTenant($refused));
            self::assertNull($repository->findOneByTenant($refused));
            self::assertNull($this->ledger()->find($refused, 1));
        }

        // Purging `bob` (its task gone) leaves the look-alikes' ledgers and counters alone.
        $this->em->remove($this->ledger()->find('bob', 1) ?? self::fail('bob has a task'));
        $this->em->flush();
        $this->ledger()->purgeScope('bob');
        $db = $this->em->getConnection();
        foreach (['Bob', 'bób'] as $key) {
            self::assertSame($key, $db->fetchOne(sprintf('SELECT scope_key FROM %s WHERE scope_key = ?', TaskNumberCounter::TABLE), [$key]));
            self::assertNotNull($this->ledger()->find($key, 1));
        }
        self::assertFalse($db->fetchOne(sprintf('SELECT scope_key FROM %s WHERE scope_key = ?', TaskNumberCounter::TABLE), ['bob']));
    }

    public function testLookAlikeRowsAFoldingDatabaseReturnsAreDropped(): void
    {
        $platform  = $this->em->getConnection()->getDatabasePlatform();
        $collation = match (true) {
            $platform instanceof SQLitePlatform        => 'NOCASE',
            $platform instanceof AbstractMySQLPlatform => 'utf8mb4_general_ci', // folds case and accents; PAD SPACE
            default                                    => null,
        };
        if ($collation === null) {
            self::markTestSkipped('PostgreSQL has no built-in case-insensitive collation; its columns compare exactly.');
        }
        $metadata = $this->em->getMetadataFactory()->getAllMetadata();
        foreach ($metadata as $class) {
            foreach (TaskBoardMetadataListener::KEY_FIELDS[$class->getName()] ?? [] as $field) {
                $class->fieldMappings[$field]->options = ['collation' => $collation];
            }
        }
        $tool = new SchemaTool($this->em);
        $tool->dropDatabase();
        $tool->createSchema($metadata);
        $this->em->clear();
        $this->owner = $this->user('owner@example.com');
        $boardTable  = $this->em->getClassMetadata(TaskBoard::class)->getTableName();
        $db          = $this->em->getConnection();

        // The look-alikes' boards first (older), then bob's own two. Distinct slugs: the unique key still folds here.
        $lookAlikes = [];
        foreach (self::LOOK_ALIKES as $index => $key) {
            $board = $this->board('Look-alike ' . $index, tenantKey: 'placeholder-' . $index);
            $board->setSlug('look-alike-' . $index);
            $this->em->flush();
            $db->update($boardTable, ['tenant_key' => $key], ['id' => $board->getId()]);
            $lookAlikes[$key] = $board;
        }
        $bob    = $this->board('Bob board', tenantKey: 'bob');
        $bobTwo = $this->board('Bob second', tenantKey: 'bob');
        $bob->setSlug('mine');
        $this->em->flush();
        $foreign = $this->task($lookAlikes['Bob'], 'foreign');
        $this->em->persist(new TaskLink($foreign, TaskLinkType::Url, 'https://example.test/e1', null, 'E-1', importSource: 'csv'));
        $own = $this->task($bob, 'own');
        $this->em->persist(new TaskLink($own, TaskLinkType::Url, 'https://example.test/e2', null, 'e-2', importSource: 'csv'));
        $this->em->persist(new ColumnSet('Foreign set', $this->owner, 'placeholder'));
        $this->em->persist($ownSet = new ColumnSet('Own set', $this->owner, 'bob'));
        $this->em->flush();
        $db->executeStatement(sprintf("UPDATE %s SET scope_key = 'Bob' WHERE scope_key = 'placeholder'", $this->em->getClassMetadata(ColumnSet::class)->getTableName()));
        // The look-alike `Bob` has its own ledger and counter (rows written before the fix, or by hand).
        $db->executeStatement(sprintf('DELETE FROM %s', TaskNumberEntry::TABLE));
        $db->executeStatement(sprintf('DELETE FROM %s', TaskNumberCounter::TABLE));
        $db->insert(TaskNumberEntry::TABLE, ['scope_key' => 'Bob', 'number' => 5, 'task_id' => $foreign->getId()]);
        $db->insert(TaskNumberCounter::TABLE, ['scope_key' => 'Bob', 'last_number' => 5]);
        $this->em->clear();

        $repository = new DoctrineOrmTaskBoardRepository($this->em);
        // Gap 1: the index lists and the first board of a tenant.
        self::assertEqualsCanonicalizing([$bob->getId(), $bobTwo->getId()], array_map(static fn (TaskBoard $board): string => $board->getId(), $repository->findActiveByTenant('bob')));
        // Both of bob's boards may share a created_at second: either is bob's first; a look-alike's older board never is.
        self::assertContains($repository->findOneByTenant('bob')?->getId(), [$bob->getId(), $bobTwo->getId()], 'a look-alike\'s older board never hides bob\'s own');
        self::assertNull($repository->findBySlugInTenant('look-alike-0', 'bob'));
        self::assertNull($repository->findBySlug('MINE'));
        self::assertSame($bob->getId(), $repository->findBySlug('mine')?->getId());

        // Gap 2: an import's "already imported elsewhere in the tenant".
        $lookup = new DoctrineTaskImportLinkLookup($this->em);
        $target = $this->em->find(TaskBoard::class, $bobTwo->getId()) ?? self::fail('the board is there');
        self::assertSame([], $lookup->findOnOtherBoards($target, 'csv', ['E-1']), 'a look-alike tenant\'s link');
        self::assertSame([], $lookup->findOnOtherBoards($target, 'csv', ['E-2']), 'a look-alike external id');
        self::assertSame([], $lookup->findOnOtherBoards($target, 'CSV', ['e-2']), 'a look-alike scope');
        self::assertSame(['e-2' => true], $lookup->findOnOtherBoards($target, 'csv', ['e-2']));

        // Gap 3: the ledger and its purge.
        self::assertSame([], $this->ledger()->findInScopes(['bob'], 5));
        self::assertNull($this->ledger()->find('bob', 5));
        $this->ledger()->purgeScope('bob');
        self::assertSame(1, (int) $db->fetchOne(sprintf('SELECT COUNT(*) FROM %s WHERE number = 5', TaskNumberEntry::TABLE)), 'purgeScope(bob) keeps Bob\'s ledger');
        self::assertSame(5, (int) $db->fetchOne(sprintf('SELECT last_number FROM %s', TaskNumberCounter::TABLE)), 'purgeScope(bob) keeps Bob\'s counter');
        try {
            (new TaskNumberReserver())->reserve($db, ['bob' => 1]);
            self::fail('bob never draws from Bob\'s counter');
        } catch (RuntimeException $exception) {
            self::assertStringContainsString('look-alike', $exception->getMessage());
        }
        self::assertSame(5, (int) $db->fetchOne(sprintf('SELECT last_number FROM %s', TaskNumberCounter::TABLE)), 'the update was rolled back');

        // Column sets of a scope.
        self::assertSame([$ownSet->getId()], array_map(static fn (ColumnSet $set): string => $set->getId(), (new DoctrineOrmColumnSetRepository($this->em))->findByScope('bob')));
    }
}
