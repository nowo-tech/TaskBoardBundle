<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Tests\Functional\Number;

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Platforms\MariaDBPlatform;
use Doctrine\ORM\Mapping\ClassMetadataFactory;
use Doctrine\ORM\Tools\SchemaTool;
use Nowo\TaskBoardBundle\Dto\TaskBoardFormData;
use Nowo\TaskBoardBundle\Entity\Task;
use Nowo\TaskBoardBundle\Entity\TaskBoard;
use Nowo\TaskBoardBundle\Exception\TaskNumberScopeConflictException;
use Nowo\TaskBoardBundle\Service\TaskBoardCreatorInterface;
use Nowo\TaskBoardBundle\Tests\Functional\FoldsKeyColumns;

use function array_filter;
use function array_values;
use function preg_match;
use function sprintf;

/**
 * Audit OM (REQ-SEC-006), from the auditor's probes: on key columns that still fold (before the UPGRADING ALTER) a write
 * for a look-alike key is suffixed or refused cleanly, never a unique-key error that closes the entity manager; and the
 * UPGRADING ALTER statements leave no schema difference with the bundle's mapping.
 */
final class FoldingKeyWritesTest extends TaskNumberTestCase
{
    use FoldsKeyColumns;

    /** TB-L1: the creator takes the look-alike tenant's slug as taken and suffixes it. */
    public function testTheCreatorSuffixesASlugALookAlikeTenantUses(): void
    {
        $this->foldKeyColumns($this->em);
        $this->owner = $this->user('owner@example.com');
        $creator     = static::getContainer()->get(TaskBoardCreatorInterface::class);
        self::assertInstanceOf(TaskBoardCreatorInterface::class, $creator);

        self::assertSame('roadmap', $creator->create(new TaskBoardFormData(name: 'Roadmap'), $this->owner, 'Bob')->getSlug());
        $second = $creator->create(new TaskBoardFormData(name: 'Roadmap'), $this->owner, 'bob');
        self::assertSame('roadmap-2', $second->getSlug());
        self::assertSame('bob', $second->getTenantKey());
        self::assertTrue($this->em->isOpen());
    }

    /** TB-L2: a task whose scope counter is a look-alike's is refused before it is scheduled (409), the manager stays usable. */
    public function testATaskInALookAlikeScopeIsRefusedBeforeTheFlush(): void
    {
        $this->foldKeyColumns($this->em);
        $this->owner = $this->user('owner@example.com');
        $upper       = $this->board('Upper', tenantKey: 'Bob');
        self::assertSame(1, $this->task($upper, 'upper task')->getNumber());
        $lower = $this->board('Lower', tenantKey: 'bob');

        try {
            $this->task($lower, 'lower task');
            self::fail('bob never draws from Bob\'s counter');
        } catch (TaskNumberScopeConflictException $exception) {
            self::assertSame(409, $exception->getStatusCode());
        }
        self::assertTrue($this->em->isOpen());
        self::assertSame([], array_filter($this->em->getUnitOfWork()->getScheduledEntityInsertions(), static fn (object $entity): bool => $entity instanceof Task));

        // A later, unrelated flush of the same (kept-alive) manager.
        $upper->setName('Renamed');
        $this->em->flush();
        $this->em->clear();
        self::assertSame('Renamed', $this->em->find(TaskBoard::class, $upper->getId())?->getName());
        self::assertSame(1, (int) $this->em->getConnection()->fetchOne(sprintf('SELECT COUNT(*) FROM %s', $this->em->getClassMetadata(Task::class)->getTableName())));
    }

    /** TB-L3: the UPGRADING ALTER statements give exactly the bundle's mapping (MySQL / MariaDB). */
    public function testTheUpgradingAlterMatchesTheMapping(): void
    {
        $platform = $this->em->getConnection()->getDatabasePlatform();
        if (!$platform instanceof AbstractMySQLPlatform) {
            self::markTestSkipped('MySQL / MariaDB only');
        }
        static::$taskBoardConfig = self::CONFIG + ['sync' => ['enabled' => true, 'credential_resolver' => 'app.sync_credentials']];
        $this->reconfigure(static::$taskBoardConfig);
        $this->foldKeyColumns($this->em);
        $db = $this->em->getConnection();
        $c  = $platform instanceof MariaDBPlatform ? 'utf8mb4_nopad_bin' : 'utf8mb4_0900_bin';
        foreach ([
            "ALTER TABLE task_board_boards MODIFY tenant_key VARCHAR(191) CHARACTER SET utf8mb4 COLLATE {$c} DEFAULT NULL, MODIFY slug VARCHAR(255) CHARACTER SET utf8mb4 COLLATE {$c} NOT NULL",
            "ALTER TABLE task_board_number_counters MODIFY scope_key VARCHAR(191) CHARACTER SET utf8mb4 COLLATE {$c} NOT NULL",
            "ALTER TABLE task_board_task_numbers MODIFY scope_key VARCHAR(191) CHARACTER SET utf8mb4 COLLATE {$c} NOT NULL",
            "ALTER TABLE task_board_column_sets MODIFY scope_key VARCHAR(191) CHARACTER SET utf8mb4 COLLATE {$c} DEFAULT NULL",
            "ALTER TABLE task_board_task_links MODIFY external_id VARCHAR(128) CHARACTER SET utf8mb4 COLLATE {$c} DEFAULT NULL, MODIFY import_source VARCHAR(64) CHARACTER SET utf8mb4 COLLATE {$c} DEFAULT NULL",
            "ALTER TABLE task_board_sync_items MODIFY external_id VARCHAR(128) CHARACTER SET utf8mb4 COLLATE {$c} NOT NULL",
            "ALTER TABLE task_board_sync_directories MODIFY scope_key VARCHAR(64) CHARACTER SET utf8mb4 COLLATE {$c} NOT NULL, MODIFY workspace_id VARCHAR(64) CHARACTER SET utf8mb4 COLLATE {$c} NOT NULL",
            // Since 2.18.0: the folders' tenant, which UPGRADING "To 2.18.0" creates with this collation.
            "ALTER TABLE task_board_folders MODIFY tenant_key VARCHAR(191) CHARACTER SET utf8mb4 COLLATE {$c} DEFAULT NULL",
            "ALTER TABLE task_board_custom_fields MODIFY tenant_key VARCHAR(191) CHARACTER SET utf8mb4 COLLATE {$c} DEFAULT NULL",
        ] as $statement) {
            $db->executeStatement($statement);
        }
        // Fresh metadata: the bundle's own mapping, not the folded one.
        $factory = new ClassMetadataFactory();
        $factory->setEntityManager($this->em);
        $diff = array_filter((new SchemaTool($this->em))->getUpdateSchemaSql($factory->getAllMetadata()), static fn (string $sql): bool => preg_match('/tenant_key|slug|scope_key|external_id|import_source|workspace_id/', $sql) === 1);
        self::assertSame([], array_values($diff));
    }
}
