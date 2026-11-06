<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Tests\Functional\Sync;

use Doctrine\Common\EventManager;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use Doctrine\DBAL\Schema\Comparator;
use Doctrine\DBAL\Schema\ForeignKeyConstraint;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Schema\Table;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\Tools\SchemaTool;

use function array_filter;
use function array_intersect;
use function array_map;
use function array_values;
use function getenv;
use function implode;
use function in_array;
use function preg_match;
use function sort;
use function str_starts_with;

/**
 * Since 1.27.0 (TB-SYNC-3): with `sync.enabled` the schema gains exactly the custom field tables and the new nullable
 * or defaulted columns of the sync tables, with the documented names and on-delete rules, on MySQL, PostgreSQL and
 * SQLite (the DDL of UPGRADING; `TASK_BOARD_PRINT_DDL=1` prints it). No table outside the sync ones changes.
 */
final class SyncSchemaFullImportTest extends SyncFunctionalTestCase
{
    /** New tables and, per changed table, the new columns (1.26 → 1.27). */
    public const NEW_TABLES = ['task_board_sync_fields', 'task_board_sync_field_values'];

    public const NEW_COLUMNS = [
        'task_board_sync_comments'     => ['parent_remote_id', 'resolved', 'assignee_person_id', 'remote_updated_at'],
        'task_board_sync_items'        => ['remote_meta', 'custom_id', 'pending_reads'],
        'task_board_task_time_entries' => ['user_name'],
    ];

    public function testTheNewTablesColumnsIndexesAndForeignKeys(): void
    {
        $schema = (new SchemaTool($this->em))->getSchemaFromMetadata($this->em->getMetadataFactory()->getAllMetadata());

        $fields = $schema->getTable('task_board_sync_fields');
        self::assertSame(['board_id', 'provider', 'remote_id'], $fields->getIndex('uniq_task_board_sync_fields_board_remote')->getColumns());
        self::assertTrue($fields->getIndex('uniq_task_board_sync_fields_board_remote')->isUnique());
        self::assertSame('CASCADE', $this->foreignKey($fields, 'board_id')->onDelete());
        $values = $schema->getTable('task_board_sync_field_values');
        self::assertSame(['task_id', 'field_id'], $values->getIndex('uniq_task_board_sync_field_values_task_field')->getColumns());
        self::assertSame(['field_id'], $values->getIndex('idx_task_board_sync_field_values_field')->getColumns());
        self::assertSame('CASCADE', $this->foreignKey($values, 'field_id')->onDelete());
        self::assertSame('CASCADE', $this->foreignKey($values, 'task_id')->onDelete());
        self::assertSame(500, $values->getColumn('value_text')->getLength());

        foreach (self::NEW_COLUMNS as $table => $columns) {
            foreach ($columns as $column) {
                $definition = $schema->getTable($table)->getColumn($column);
                // Additive: nullable or defaulted, so existing rows need no rewrite.
                self::assertTrue(!$definition->getNotnull() || $definition->getDefault() !== null, $table . '.' . $column);
            }
        }
        self::assertSame('SET NULL', $this->foreignKey($schema->getTable('task_board_sync_comments'), 'assignee_person_id')->onDelete());
        // Files stay named after an unlink, and manual time stays when its user is deleted.
        foreach (['task_board_sync_attachments' => 'item_id', 'task_board_task_time_entries' => 'user_id'] as $table => $column) {
            self::assertFalse($schema->getTable($table)->getColumn($column)->getNotnull(), $table);
            self::assertSame('SET NULL', $this->foreignKey($schema->getTable($table), $column)->onDelete(), $table);
        }
    }

    public function testTheMigrationIsAdditiveOnMysqlPostgresqlAndSqlite(): void
    {
        $connection = DriverManager::getConnection(['driver' => 'pdo_mysql', 'serverVersion' => '8.0.40', 'host' => 'localhost', 'dbname' => 'none']);
        $events     = $this->em->getEventManager();
        self::assertInstanceOf(EventManager::class, $events);
        $em     = new EntityManager($connection, $this->em->getConfiguration(), $events);
        $target = (new SchemaTool($em))->getSchemaFromMetadata($em->getMetadataFactory()->getAllMetadata());
        foreach (['mysql' => $connection->getDatabasePlatform(), 'pgsql' => new PostgreSQLPlatform(), 'sqlite' => new SQLitePlatform()] as $name => $platform) {
            $sql = $this->migration($target, $platform);
            if (getenv('TASK_BOARD_PRINT_DDL') !== false) {
                echo "\n-- " . $name . "\n" . implode(";\n", $sql) . ";\n";
            }
            foreach ($sql as $statement) {
                // The two relaxed foreign keys are the only changes that are not additions (see the test above).
                if (preg_match('/task_board_sync_attachments|task_board_task_time_entries/', $statement) === 1) {
                    continue;
                }
                // SQLite adds a foreign-key column by rebuilding the table (copy, drop, create, copy back): run it with
                // foreign keys off (UPGRADING). The other platforms only add.
                self::assertDoesNotMatchRegularExpression($name === 'sqlite' ? '/^ALTER TABLE \S+ (DROP|CHANGE|MODIFY)\b/i' : '/^(DROP TABLE|ALTER TABLE \S+ (DROP|CHANGE|MODIFY))\b/i', $statement, $name . ': ' . $statement);
                self::assertMatchesRegularExpression('/task_board_sync_|__temp__/', $statement, $name . ': ' . $statement);
            }
            $created = array_values(array_filter(array_map(static fn (string $s): ?string => preg_match('/^CREATE TABLE (\S+?)\s*\(/', $s, $m) === 1 ? $m[1] : null, $sql), static fn (?string $table): bool => $table !== null && !str_starts_with($table, '__temp__') && !isset(self::NEW_COLUMNS[$table])));
            sort($created);
            self::assertSame(['task_board_sync_field_values', 'task_board_sync_fields'], array_values(array_filter($created, static fn (?string $table): bool => !in_array($table, ['task_board_sync_attachments', 'task_board_sync_comments', 'task_board_task_time_entries'], true))), $name);
        }
    }

    /**
     * The statements that take a 1.26 schema (the target without the 1.27 tables and columns) to the target.
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

        // 1.26: both were NOT NULL and ON DELETE CASCADE.
        foreach (['task_board_sync_attachments' => 'item_id', 'task_board_task_time_entries' => 'user_id'] as $name => $column) {
            $table = $old->getTable($name);
            $table->getColumn($column)->setNotnull(true);
            foreach ($table->getForeignKeys() as $key => $foreignKey) {
                if ($foreignKey->getLocalColumns() === [$column]) {
                    $table->removeForeignKey($key);
                    $table->addForeignKeyConstraint($foreignKey->getForeignTableName(), [$column], $foreignKey->getForeignColumns(), ['onDelete' => 'CASCADE'], $key);
                }
            }
        }

        return $platform->getAlterSchemaSQL((new Comparator($platform))->compareSchemas($old, $target));
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
