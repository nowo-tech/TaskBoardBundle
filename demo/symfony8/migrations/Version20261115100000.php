<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * TaskBoardBundle 2.19 (Beacon spec 234, FR-031): board folders. A new table and a nullable folder on every board (every
 * board stays folderless). The folder's tenant key compares byte for byte, as the boards' (REQ-SEC-006). The table
 * collation is the demo's task_board_boards one (utf8mb4_unicode_ci), which the foreign key needs (audit PW-L4).
 */
final class Version20261115100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'TaskBoardBundle 2.19: task_board_folders, task_board_boards.folder_id';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE task_board_folders (id VARCHAR(36) NOT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, name VARCHAR(255) NOT NULL, tenant_key VARCHAR(191) DEFAULT NULL COLLATE `utf8mb4_0900_bin`, description LONGTEXT DEFAULT NULL, position INT NOT NULL, INDEX idx_task_board_folders_tenant (tenant_key), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci`');
        $this->addSql('ALTER TABLE task_board_boards ADD folder_id VARCHAR(36) DEFAULT NULL');
        $this->addSql('ALTER TABLE task_board_boards ADD CONSTRAINT FK_97612D99162CB942 FOREIGN KEY (folder_id) REFERENCES task_board_folders (id) ON DELETE SET NULL');
        $this->addSql('CREATE INDEX IDX_97612D99162CB942 ON task_board_boards (folder_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE task_board_boards DROP FOREIGN KEY FK_97612D99162CB942');
        $this->addSql('DROP INDEX IDX_97612D99162CB942 ON task_board_boards');
        $this->addSql('ALTER TABLE task_board_boards DROP folder_id');
        $this->addSql('DROP TABLE task_board_folders');
    }
}
