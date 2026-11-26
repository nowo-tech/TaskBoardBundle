<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * TaskBoardBundle 3.3 (CU-3): native custom fields from a ClickUp import — the provenance marker (unique per board), the
 * provider option map and the definition's version (REQ-RUNTIME-003). Additive.
 */
final class Version20261126120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'TaskBoardBundle 3.3: custom field provenance and version';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE task_board_custom_fields ADD source_ref VARCHAR(191) COLLATE `utf8mb4_0900_bin` DEFAULT NULL, ADD source_options JSON DEFAULT NULL, ADD version INT DEFAULT 1 NOT NULL');
        $this->addSql('CREATE UNIQUE INDEX uniq_task_board_custom_fields_source ON task_board_custom_fields (board_id, source_ref)');
        // Audit ZCF-L2: imported fields deleted on a board (not created again by a re-import).
        $this->addSql('CREATE TABLE task_board_custom_field_tombstones (id VARCHAR(36) NOT NULL, deleted_at DATETIME NOT NULL, name VARCHAR(128) NOT NULL, source_ref VARCHAR(191) NOT NULL COLLATE `utf8mb4_0900_bin`, board_id VARCHAR(36) NOT NULL, UNIQUE INDEX uniq_task_board_custom_field_tombstones (board_id, source_ref), INDEX IDX_A7286CF5E7EC5785 (board_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE task_board_custom_field_tombstones ADD CONSTRAINT FK_A7286CF5E7EC5785 FOREIGN KEY (board_id) REFERENCES task_board_boards (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE task_board_custom_field_tombstones');
        $this->addSql('DROP INDEX uniq_task_board_custom_fields_source ON task_board_custom_fields');
        $this->addSql('ALTER TABLE task_board_custom_fields DROP source_ref, DROP source_options, DROP version');
    }
}
