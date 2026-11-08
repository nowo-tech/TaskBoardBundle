<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Types\Types;
use Doctrine\Migrations\AbstractMigration;

/**
 * TaskBoardBundle 2.3 (spec 217, audit IC-I4): a moved synced task stays reachable from its tombstone item. Written with the
 * schema API, so up() and down() run on MySQL, PostgreSQL and SQLite, with the names Doctrine itself generates.
 */
final class Version20261108110000 extends AbstractMigration
{
    private const TABLE = 'task_board_sync_items';

    private const FOREIGN_KEY = 'FK_3BD8DF5A4F92A733';

    private const INDEX = 'idx_task_board_sync_items_detached';

    public function getDescription(): string
    {
        return 'TaskBoardBundle 2.3: task_board_sync_items.detached_task_id';
    }

    public function up(Schema $schema): void
    {
        $table = $schema->getTable(self::TABLE);
        $table->addColumn('detached_task_id', Types::STRING, ['length' => 36, 'notnull' => false]);
        $table->addIndex(['detached_task_id'], self::INDEX);
        $table->addForeignKeyConstraint('task_board_tasks', ['detached_task_id'], ['id'], ['onDelete' => 'SET NULL'], self::FOREIGN_KEY);
    }

    public function down(Schema $schema): void
    {
        $table = $schema->getTable(self::TABLE);
        // DBAL 4.3 renamed removeForeignKey(); the demo runs on either.
        method_exists($table, 'dropForeignKey') ? $table->dropForeignKey(self::FOREIGN_KEY) : $table->removeForeignKey(self::FOREIGN_KEY);
        $table->dropIndex(self::INDEX);
        $table->dropColumn('detached_task_id');
    }
}
