<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Tests\Functional\Sync;

use Doctrine\Common\EventManager;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Schema\Column;
use Doctrine\DBAL\Schema\Comparator;
use Doctrine\DBAL\Schema\ForeignKeyConstraint;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Schema\Table;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\Tools\SchemaTool;
use Nowo\TaskBoardBundle\Sync\People\TaskSyncCommentLegacyAuthorMigrator;
use Nowo\TaskBoardBundle\Sync\People\TaskSyncPeopleDirectoryService;
use Nowo\TaskBoardBundle\Sync\People\TaskSyncPeopleProjector;
use Nowo\TaskBoardBundle\Sync\People\TaskSyncPeopleReassigner;
use Nowo\TaskBoardBundle\Sync\People\TaskSyncPeopleScanner;
use Nowo\TaskBoardBundle\Sync\People\TaskSyncPersonRepository;
use Nowo\TaskBoardBundle\Sync\TaskSyncSourceScanner;

use function array_filter;
use function array_intersect;
use function array_map;
use function array_values;
use function getenv;
use function implode;
use function preg_match;
use function sort;
use function strtolower;

/**
 * Since 1.25 (§6.3 / §6.4, §15.2 / §15.3): with `sync.enabled` the schema gains exactly the people tables and the new
 * nullable / defaulted columns, with the documented index names and on-delete rules; the services compile.
 */
final class SyncSchemaPeopleTest extends SyncFunctionalTestCase
{
    /** New tables and, per changed table, the new columns (1.24 → 1.25). */
    public const NEW_TABLES = ['task_board_sync_directories', 'task_board_sync_people', 'task_board_sync_members'];

    public const NEW_COLUMNS = [
        'task_board_connections'   => ['directory_id', 'source_type', 'source_id', 'status_column_map'],
        'task_board_sync_items'    => ['creator_person_id', 'unmatched_people', 'backfill_level', 'item_kind'],
        'task_board_sync_comments' => ['author_person_id'],
        'task_board_sync_runs'     => ['time_mode'],
    ];

    public function testTheNewTablesColumnsIndexesAndForeignKeys(): void
    {
        $schema = (new SchemaTool($this->em))->getSchemaFromMetadata($this->em->getMetadataFactory()->getAllMetadata());

        $directories = $schema->getTable('task_board_sync_directories');
        self::assertSame(['provider', 'scope_key', 'workspace_id'], $directories->getIndex('uniq_task_board_sync_directories')->getColumns());
        self::assertTrue($directories->getIndex('uniq_task_board_sync_directories')->isUnique());
        self::assertSame(['anonymous_counter', 'created_at', 'id', 'provider', 'reviewed_at', 'reviewed_by_id', 'scope_key', 'workspace_id'], $this->columns($directories));
        self::assertSame('SET NULL', $this->foreignKey($directories, 'reviewed_by_id')->onDelete());

        $people = $schema->getTable('task_board_sync_people');
        self::assertSame(['directory_id', 'provider_user_id'], $people->getIndex('uniq_task_board_sync_people_dir_remote')->getColumns());
        self::assertTrue($people->getIndex('uniq_task_board_sync_people_dir_remote')->isUnique());
        self::assertSame(['directory_id', 'mapping'], $people->getIndex('idx_task_board_sync_people_dir_mapping')->getColumns());
        self::assertSame(['mapped_user_id'], $people->getIndex('idx_task_board_sync_people_mapped')->getColumns());
        self::assertSame(['directory_id', 'email_normalized'], $people->getIndex('idx_task_board_sync_people_dir_email')->getColumns());
        self::assertSame(['anonymised_at', 'directory_id', 'email', 'email_changed_at', 'email_normalized', 'first_seen_at', 'id', 'last_seen_at', 'mapped_at', 'mapped_by_id', 'mapped_user_id', 'mapping', 'name', 'provider_user_id', 'reassign_external', 'reassign_id', 'reassign_to_user_id', 'remote_role', 'seen_kinds', 'suggested_user_id'], $this->columns($people));
        self::assertSame('CASCADE', $this->foreignKey($people, 'directory_id')->onDelete());
        foreach (['mapped_user_id', 'suggested_user_id', 'mapped_by_id', 'reassign_to_user_id'] as $user) {
            self::assertSame('SET NULL', $this->foreignKey($people, $user)->onDelete(), $user);
            self::assertTrue($people->getColumn($user)->getNotnull() === false, $user);
        }
        self::assertSame(120, $people->getColumn('name')->getLength());
        self::assertSame(254, $people->getColumn('email')->getLength());

        $members = $schema->getTable('task_board_sync_members');
        self::assertSame(['task_member_id'], $members->getIndex('uniq_task_board_sync_members_member')->getColumns());
        self::assertTrue($members->getIndex('uniq_task_board_sync_members_member')->isUnique());
        self::assertSame(['person_id'], $members->getIndex('idx_task_board_sync_members_person')->getColumns());
        foreach (['item_id', 'task_member_id', 'person_id'] as $column) {
            self::assertSame('CASCADE', $this->foreignKey($members, $column)->onDelete(), $column);
        }

        foreach (self::NEW_COLUMNS as $table => $columns) {
            foreach ($columns as $column) {
                self::assertTrue($schema->getTable($table)->hasColumn($column), $table . '.' . $column);
                $definition = $schema->getTable($table)->getColumn($column);
                // Additive: nullable or defaulted, so existing rows need no rewrite (§6.4).
                self::assertTrue(!$definition->getNotnull() || $definition->getDefault() !== null, $table . '.' . $column);
            }
        }
        self::assertSame('0', (string) $schema->getTable('task_board_sync_items')->getColumn('backfill_level')->getDefault());
        self::assertSame('task', $schema->getTable('task_board_sync_items')->getColumn('item_kind')->getDefault());
        self::assertSame('list', $schema->getTable('task_board_connections')->getColumn('source_type')->getDefault());
        self::assertSame('SET NULL', $this->foreignKey($schema->getTable('task_board_connections'), 'directory_id')->onDelete());
        self::assertSame('SET NULL', $this->foreignKey($schema->getTable('task_board_sync_items'), 'creator_person_id')->onDelete());
        self::assertSame('SET NULL', $this->foreignKey($schema->getTable('task_board_sync_comments'), 'author_person_id')->onDelete());
    }

    public function testTheMysqlMigrationIsAdditiveAndOnlyTouchesTheSyncTables(): void
    {
        $connection = DriverManager::getConnection(['driver' => 'pdo_mysql', 'serverVersion' => '8.0.40', 'host' => 'localhost', 'dbname' => 'none']);
        $events     = $this->em->getEventManager();
        self::assertInstanceOf(EventManager::class, $events);
        $em     = new EntityManager($connection, $this->em->getConfiguration(), $events);
        $target = (new SchemaTool($em))->getSchemaFromMetadata($em->getMetadataFactory()->getAllMetadata());
        $sql    = $this->migration($target, $connection->getDatabasePlatform());

        if (getenv('TASK_BOARD_PRINT_DDL') !== false) {
            echo "\n" . implode(";\n", $sql) . ";\n";
        }
        foreach ($sql as $statement) {
            self::assertDoesNotMatchRegularExpression('/^(DROP|ALTER TABLE \S+ (DROP|CHANGE|MODIFY))\b/i', $statement, $statement);
            self::assertMatchesRegularExpression('/task_board_(sync_|connections)/', $statement, $statement);
        }
        $created = array_values(array_filter(array_map(static fn (string $s): ?string => preg_match('/^CREATE TABLE (\S+)/', $s, $m) === 1 ? $m[1] : null, $sql)));
        sort($created);
        self::assertSame(['task_board_sync_directories', 'task_board_sync_members', 'task_board_sync_people'], $created);
        self::assertContains('ALTER TABLE task_board_sync_runs ADD time_mode VARCHAR(16) DEFAULT NULL', $sql);
    }

    public function testThePeopleServicesCompile(): void
    {
        foreach ([TaskSyncPersonRepository::class, TaskSyncPeopleDirectoryService::class, TaskSyncPeopleReassigner::class, TaskSyncPeopleProjector::class, TaskSyncPeopleScanner::class, TaskSyncSourceScanner::class, TaskSyncCommentLegacyAuthorMigrator::class] as $id) {
            self::assertInstanceOf($id, self::getContainer()->get($id));
        }
    }

    /**
     * The statements that take a 1.24 schema (the target without the 1.25 tables and columns) to the target.
     *
     * @return list<string>
     */
    private function migration(Schema $target, AbstractPlatform $platform): array
    {
        $old = clone $target;
        foreach (self::NEW_TABLES as $table) {
            $old->dropTable($table);
        }
        foreach (self::NEW_COLUMNS as $name => $columns) {
            $table = $old->getTable($name);
            foreach ($table->getForeignKeys() as $key => $foreignKey) {
                if (array_intersect($foreignKey->getLocalColumns(), $columns) !== []) {
                    $table->removeForeignKey($key);
                }
            }
            foreach ($table->getIndexes() as $key => $index) {
                if (!$index->isPrimary() && array_intersect($index->getColumns(), $columns) !== []) {
                    $table->dropIndex($key);
                }
            }
            foreach ($columns as $column) {
                $table->dropColumn($column);
            }
        }
        $diff = (new Comparator($platform))->compareSchemas($old, $target);

        return $platform->getAlterSchemaSQL($diff);
    }

    /**
     * @return list<string>
     */
    private function columns(Table $table): array
    {
        $names = array_map(static fn (Column $column): string => strtolower($column->getName()), $table->getColumns());
        sort($names);

        return $names;
    }

    private function foreignKey(Table $table, string $column): ForeignKeyConstraint
    {
        foreach ($table->getForeignKeys() as $foreignKey) {
            if ($foreignKey->getLocalColumns() === [$column]) {
                return $foreignKey;
            }
        }
        self::fail('No foreign key on ' . $table->getName() . '.' . $column);
    }
}
