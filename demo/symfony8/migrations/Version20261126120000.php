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
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX uniq_task_board_custom_fields_source ON task_board_custom_fields');
        $this->addSql('ALTER TABLE task_board_custom_fields DROP source_ref, DROP source_options, DROP version');
    }
}
