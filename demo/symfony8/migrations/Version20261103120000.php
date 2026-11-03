<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * TaskBoardBundle 1.22: the import scope of a task link (idempotent re-imports match on import_source + external_id).
 */
final class Version20261103120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'TaskBoardBundle 1.22: task_board_task_links.import_source';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE task_board_task_links ADD import_source VARCHAR(64) DEFAULT NULL');
        $this->addSql('CREATE INDEX idx_task_board_task_links_import ON task_board_task_links (import_source, external_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX idx_task_board_task_links_import ON task_board_task_links');
        $this->addSql('ALTER TABLE task_board_task_links DROP import_source');
    }
}
