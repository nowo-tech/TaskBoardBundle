<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * TaskBoardBundle 1.10: board tenant key (tenancy) and host object links (TaskLinkType::HostObject).
 */
final class Version20261028120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'TaskBoardBundle 1.10: task_board_boards.tenant_key, task_board_task_links.object_type / object_id';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE task_board_boards ADD tenant_key VARCHAR(191) DEFAULT NULL');
        $this->addSql('CREATE INDEX idx_task_board_boards_tenant ON task_board_boards (tenant_key)');
        $this->addSql('ALTER TABLE task_board_task_links ADD object_type VARCHAR(64) DEFAULT NULL, ADD object_id VARCHAR(191) DEFAULT NULL');
        $this->addSql('CREATE INDEX idx_task_board_task_links_object ON task_board_task_links (object_type, object_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX idx_task_board_task_links_object ON task_board_task_links');
        $this->addSql('ALTER TABLE task_board_task_links DROP object_type, DROP object_id');
        $this->addSql('DROP INDEX idx_task_board_boards_tenant ON task_board_boards');
        $this->addSql('ALTER TABLE task_board_boards DROP tenant_key');
    }
}
