<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * TaskBoardBundle 3.7 (Beacon spec 235, FR-004): the correlation id of every task history row, indexed. Additive.
 */
final class Version20261128120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'TaskBoardBundle 3.7: task history correlation id';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE task_board_task_change_history ADD correlation_id VARCHAR(64) DEFAULT NULL');
        $this->addSql('CREATE INDEX task_board_change_history_correlation_idx ON task_board_task_change_history (correlation_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX task_board_change_history_correlation_idx ON task_board_task_change_history');
        $this->addSql('ALTER TABLE task_board_task_change_history DROP correlation_id');
    }
}
