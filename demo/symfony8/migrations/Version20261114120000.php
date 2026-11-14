<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * TaskBoardBundle 2.19 (Beacon spec 234, FR-028): native custom fields and their values. Two new tables; a tenant
 * field's tenant key compares byte for byte (REQ-SEC-006).
 */
final class Version20261114120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'TaskBoardBundle 2.19: task_board_custom_fields, task_board_custom_field_values';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE task_board_custom_fields (id VARCHAR(36) NOT NULL, created_at DATETIME NOT NULL, name VARCHAR(128) NOT NULL, options JSON NOT NULL, field_type VARCHAR(16) NOT NULL, tenant_key VARCHAR(191) DEFAULT NULL COLLATE `utf8mb4_0900_bin`, position INT NOT NULL, board_id VARCHAR(36) DEFAULT NULL, INDEX idx_task_board_custom_fields_tenant (tenant_key), INDEX IDX_E928CA7DE7EC5785 (board_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci`');
        $this->addSql('CREATE TABLE task_board_custom_field_values (id VARCHAR(36) NOT NULL, updated_at DATETIME NOT NULL, text_value LONGTEXT DEFAULT NULL, number_value NUMERIC(30, 10) DEFAULT NULL, task_id VARCHAR(36) NOT NULL, field_id VARCHAR(36) NOT NULL, INDEX idx_task_board_custom_field_values_field (field_id), UNIQUE INDEX uniq_task_board_custom_field_values (task_id, field_id), INDEX IDX_E5169D958DB60186 (task_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci`');
        $this->addSql('ALTER TABLE task_board_custom_fields ADD CONSTRAINT FK_E928CA7DE7EC5785 FOREIGN KEY (board_id) REFERENCES task_board_boards (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE task_board_custom_field_values ADD CONSTRAINT FK_E5169D958DB60186 FOREIGN KEY (task_id) REFERENCES task_board_tasks (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE task_board_custom_field_values ADD CONSTRAINT FK_E5169D95443707B0 FOREIGN KEY (field_id) REFERENCES task_board_custom_fields (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE task_board_custom_field_values');
        $this->addSql('DROP TABLE task_board_custom_fields');
    }
}
