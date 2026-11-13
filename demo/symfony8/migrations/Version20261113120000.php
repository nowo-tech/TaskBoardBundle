<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * TaskBoardBundle 2.16 (Beacon spec 234, FR-061, task_templates.enabled): the task template table. Additive: a new
 * table, nothing existing is rewritten or dropped.
 */
final class Version20261113120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'TaskBoardBundle 2.16: task_board_task_templates';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE task_board_task_templates (id VARCHAR(36) NOT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, tenant_key VARCHAR(191) DEFAULT NULL, name VARCHAR(120) NOT NULL, body LONGTEXT DEFAULT NULL, default_tags JSON NOT NULL, default_column_id VARCHAR(36) DEFAULT NULL, default_column_name VARCHAR(128) DEFAULT NULL, board_id VARCHAR(36) DEFAULT NULL, INDEX idx_task_board_task_templates_tenant (tenant_key), INDEX IDX_DA1B12A8E7EC5785 (board_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE task_board_task_templates ADD CONSTRAINT FK_DA1B12A8E7EC5785 FOREIGN KEY (board_id) REFERENCES task_board_boards (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE task_board_task_templates');
    }
}
