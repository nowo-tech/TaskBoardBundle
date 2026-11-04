<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * TaskBoardBundle 1.23: archived tasks (every host) and the provider sync tables (the demo enables the sync).
 */
final class Version20261104120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'TaskBoardBundle 1.23: task archive and provider sync tables';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE task_board_tasks ADD archived_at DATETIME DEFAULT NULL, ADD archive_reason VARCHAR(16) DEFAULT NULL');
        $this->addSql('CREATE INDEX task_board_tasks_board_archived_idx ON task_board_tasks (board_id, archived_at)');
        $this->addSql('CREATE TABLE task_board_connections (id VARCHAR(36) NOT NULL, created_at DATETIME NOT NULL, last_run_at DATETIME DEFAULT NULL, last_success_at DATETIME DEFAULT NULL, watermark DATETIME DEFAULT NULL, sync_cursor JSON DEFAULT NULL, running_run_id VARCHAR(36) DEFAULT NULL, heartbeat_at DATETIME DEFAULT NULL, provider VARCHAR(32) NOT NULL, import_scope VARCHAR(64) NOT NULL, connection_ref VARCHAR(191) NOT NULL, container_id VARCHAR(128) NOT NULL, container_label VARCHAR(255) NOT NULL, container_path JSON NOT NULL, options JSON NOT NULL, board_id VARCHAR(36) NOT NULL, created_by_id INT DEFAULT NULL, UNIQUE INDEX uniq_task_board_connections_board (board_id), INDEX IDX_947E513BB03A8386 (created_by_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE task_board_sync_items (id VARCHAR(36) NOT NULL, field_hashes JSON NOT NULL, conflict_hashes JSON NOT NULL, remote_archived_at DATETIME DEFAULT NULL, last_seen_run_id VARCHAR(36) DEFAULT NULL, board_id VARCHAR(36) NOT NULL, external_id VARCHAR(128) NOT NULL, remote_updated_at DATETIME NOT NULL, connection_id VARCHAR(36) NOT NULL, task_id VARCHAR(36) DEFAULT NULL, INDEX idx_task_board_sync_items_task (task_id), UNIQUE INDEX uniq_task_board_sync_items_board_ext (board_id, external_id), INDEX IDX_3BD8DF5ADD03F01 (connection_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE task_board_sync_runs (finished_at DATETIME DEFAULT NULL, heartbeat_at DATETIME DEFAULT NULL, status VARCHAR(16) NOT NULL, cancel_requested TINYINT NOT NULL, report JSON NOT NULL, run_order BIGINT NOT NULL, id VARCHAR(36) NOT NULL, board_id VARCHAR(36) NOT NULL, run_trigger VARCHAR(16) NOT NULL, mode VARCHAR(16) NOT NULL, dry_run TINYINT NOT NULL, started_at DATETIME NOT NULL, connection_id VARCHAR(36) NOT NULL, actor_id INT DEFAULT NULL, INDEX idx_task_board_sync_runs_board (board_id, started_at), INDEX IDX_26B4BC74DD03F01 (connection_id), INDEX IDX_26B4BC7410DAF24A (actor_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE task_board_sync_comments (id VARCHAR(36) NOT NULL, remote_id VARCHAR(128) NOT NULL, body_hash VARCHAR(64) NOT NULL, local_hash VARCHAR(64) NOT NULL, item_id VARCHAR(36) NOT NULL, comment_id VARCHAR(36) NOT NULL, UNIQUE INDEX uniq_task_board_sync_comments_item_remote (item_id, remote_id), INDEX IDX_BDECD5F5126F525E (item_id), INDEX IDX_BDECD5F5F8697D13 (comment_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE task_board_sync_attachments (id VARCHAR(36) NOT NULL, remote_id VARCHAR(128) NOT NULL, remote_size BIGINT DEFAULT NULL, remote_updated_at DATETIME DEFAULT NULL, stored_ref VARCHAR(191) NOT NULL, stored_url VARCHAR(2048) NOT NULL, name VARCHAR(255) NOT NULL, mime VARCHAR(127) NOT NULL, size BIGINT NOT NULL, sha256 VARCHAR(64) DEFAULT NULL, item_id VARCHAR(36) NOT NULL, task_id VARCHAR(36) NOT NULL, link_id VARCHAR(36) DEFAULT NULL, UNIQUE INDEX uniq_task_board_sync_attachments_task_remote (task_id, remote_id), INDEX IDX_A44E84EF126F525E (item_id), INDEX IDX_A44E84EF8DB60186 (task_id), INDEX IDX_A44E84EFADA40271 (link_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE task_board_connections ADD CONSTRAINT FK_947E513BE7EC5785 FOREIGN KEY (board_id) REFERENCES task_board_boards (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE task_board_connections ADD CONSTRAINT FK_947E513BB03A8386 FOREIGN KEY (created_by_id) REFERENCES users (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE task_board_sync_items ADD CONSTRAINT FK_3BD8DF5ADD03F01 FOREIGN KEY (connection_id) REFERENCES task_board_connections (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE task_board_sync_items ADD CONSTRAINT FK_3BD8DF5A8DB60186 FOREIGN KEY (task_id) REFERENCES task_board_tasks (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE task_board_sync_runs ADD CONSTRAINT FK_26B4BC74DD03F01 FOREIGN KEY (connection_id) REFERENCES task_board_connections (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE task_board_sync_runs ADD CONSTRAINT FK_26B4BC7410DAF24A FOREIGN KEY (actor_id) REFERENCES users (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE task_board_sync_comments ADD CONSTRAINT FK_BDECD5F5126F525E FOREIGN KEY (item_id) REFERENCES task_board_sync_items (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE task_board_sync_comments ADD CONSTRAINT FK_BDECD5F5F8697D13 FOREIGN KEY (comment_id) REFERENCES task_board_task_comments (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE task_board_sync_attachments ADD CONSTRAINT FK_A44E84EF126F525E FOREIGN KEY (item_id) REFERENCES task_board_sync_items (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE task_board_sync_attachments ADD CONSTRAINT FK_A44E84EF8DB60186 FOREIGN KEY (task_id) REFERENCES task_board_tasks (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE task_board_sync_attachments ADD CONSTRAINT FK_A44E84EFADA40271 FOREIGN KEY (link_id) REFERENCES task_board_task_links (id) ON DELETE SET NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE task_board_connections DROP FOREIGN KEY FK_947E513BE7EC5785');
        $this->addSql('ALTER TABLE task_board_connections DROP FOREIGN KEY FK_947E513BB03A8386');
        $this->addSql('ALTER TABLE task_board_sync_items DROP FOREIGN KEY FK_3BD8DF5ADD03F01');
        $this->addSql('ALTER TABLE task_board_sync_items DROP FOREIGN KEY FK_3BD8DF5A8DB60186');
        $this->addSql('ALTER TABLE task_board_sync_runs DROP FOREIGN KEY FK_26B4BC74DD03F01');
        $this->addSql('ALTER TABLE task_board_sync_runs DROP FOREIGN KEY FK_26B4BC7410DAF24A');
        $this->addSql('ALTER TABLE task_board_sync_comments DROP FOREIGN KEY FK_BDECD5F5126F525E');
        $this->addSql('ALTER TABLE task_board_sync_comments DROP FOREIGN KEY FK_BDECD5F5F8697D13');
        $this->addSql('ALTER TABLE task_board_sync_attachments DROP FOREIGN KEY FK_A44E84EF126F525E');
        $this->addSql('ALTER TABLE task_board_sync_attachments DROP FOREIGN KEY FK_A44E84EF8DB60186');
        $this->addSql('ALTER TABLE task_board_sync_attachments DROP FOREIGN KEY FK_A44E84EFADA40271');
        $this->addSql('DROP TABLE task_board_sync_attachments');
        $this->addSql('DROP TABLE task_board_sync_comments');
        $this->addSql('DROP TABLE task_board_sync_runs');
        $this->addSql('DROP TABLE task_board_sync_items');
        $this->addSql('DROP TABLE task_board_connections');
        $this->addSql('DROP INDEX task_board_tasks_board_archived_idx ON task_board_tasks');
        $this->addSql('ALTER TABLE task_board_tasks DROP archived_at, DROP archive_reason');
    }
}
