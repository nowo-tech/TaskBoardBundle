<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * TaskBoardBundle 3.2 (CU-2): import plans of the guided import from ClickUp — two new tables. Additive.
 */
final class Version20261125120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'TaskBoardBundle 3.2: import plans';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE task_board_sync_import_plan_items (id VARCHAR(36) NOT NULL, board_id VARCHAR(36) DEFAULT NULL, create_board TINYINT NOT NULL, status_map JSON NOT NULL, remote_statuses JSON DEFAULT NULL, status VARCHAR(16) NOT NULL, phase VARCHAR(16) NOT NULL, connection_id VARCHAR(36) DEFAULT NULL, run_id VARCHAR(36) DEFAULT NULL, report JSON NOT NULL, error_code VARCHAR(64) DEFAULT NULL, position INT NOT NULL, source_type VARCHAR(8) NOT NULL, source_id VARCHAR(128) COLLATE `utf8mb4_0900_bin` NOT NULL, source_label VARCHAR(255) NOT NULL, source_path JSON NOT NULL, board_name VARCHAR(255) NOT NULL, plan_id VARCHAR(36) NOT NULL, INDEX idx_task_board_sync_import_plan_items_board (board_id), UNIQUE INDEX uniq_task_board_sync_import_plan_items_source (plan_id, source_type, source_id), INDEX IDX_3CE5A2CFE899029B (plan_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE task_board_sync_import_plans (id VARCHAR(36) NOT NULL, created_at DATETIME NOT NULL, workspace_id VARCHAR(64) COLLATE `utf8mb4_0900_bin` DEFAULT NULL, workspace_name VARCHAR(255) DEFAULT NULL, status VARCHAR(16) NOT NULL, layout VARCHAR(16) NOT NULL, reimport_mode VARCHAR(16) NOT NULL, step VARCHAR(16) NOT NULL, options JSON NOT NULL, field_map JSON NOT NULL, started_by_id VARCHAR(191) COLLATE `utf8mb4_0900_bin` DEFAULT NULL, started_at DATETIME DEFAULT NULL, finished_at DATETIME DEFAULT NULL, heartbeat_at DATETIME DEFAULT NULL, resume_at DATETIME DEFAULT NULL, report JSON NOT NULL, lock_key VARCHAR(64) DEFAULT NULL, error_code VARCHAR(64) DEFAULT NULL, cancel_requested TINYINT NOT NULL, version INT NOT NULL, tenant_key VARCHAR(191) COLLATE `utf8mb4_0900_bin` DEFAULT NULL, provider VARCHAR(32) NOT NULL, connection_ref VARCHAR(191) NOT NULL, connection_label VARCHAR(255) NOT NULL, created_by_id VARCHAR(191) COLLATE `utf8mb4_0900_bin` DEFAULT NULL, directory_id VARCHAR(36) DEFAULT NULL, INDEX idx_task_board_sync_import_plans_tenant (tenant_key, status), UNIQUE INDEX uniq_task_board_sync_import_plans_lock (lock_key), INDEX IDX_87D435692C94069F (directory_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE task_board_sync_import_plan_items ADD CONSTRAINT FK_3CE5A2CFE899029B FOREIGN KEY (plan_id) REFERENCES task_board_sync_import_plans (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE task_board_sync_import_plans ADD CONSTRAINT FK_87D435692C94069F FOREIGN KEY (directory_id) REFERENCES task_board_sync_directories (id) ON DELETE SET NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE task_board_sync_import_plan_items DROP FOREIGN KEY FK_3CE5A2CFE899029B');
        $this->addSql('ALTER TABLE task_board_sync_import_plans DROP FOREIGN KEY FK_87D435692C94069F');
        $this->addSql('DROP TABLE task_board_sync_import_plan_items');
        $this->addSql('DROP TABLE task_board_sync_import_plans');
    }
}
