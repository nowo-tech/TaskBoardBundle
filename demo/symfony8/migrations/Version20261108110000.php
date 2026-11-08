<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * TaskBoardBundle 2.3 (spec 217, audit IC-I4): a moved synced task stays reachable from its tombstone item.
 */
final class Version20261108110000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'TaskBoardBundle 2.3: task_board_sync_items.detached_task_id';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE task_board_sync_items ADD detached_task_id VARCHAR(36) DEFAULT NULL');
        $this->addSql('CREATE INDEX idx_task_board_sync_items_detached ON task_board_sync_items (detached_task_id)');
        $this->addSql('ALTER TABLE task_board_sync_items ADD CONSTRAINT fk_task_board_sync_items_detached FOREIGN KEY (detached_task_id) REFERENCES task_board_tasks (id) ON DELETE SET NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE task_board_sync_items DROP FOREIGN KEY fk_task_board_sync_items_detached');
        $this->addSql('DROP INDEX idx_task_board_sync_items_detached ON task_board_sync_items');
        $this->addSql('ALTER TABLE task_board_sync_items DROP detached_task_id');
    }
}
