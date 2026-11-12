<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * TaskBoardBundle 2.12 (Beacon D-20261111-12): the billable flag of manual time entries. Nullable, no default: existing
 * rows stay "not set"; nothing is rewritten or dropped.
 */
final class Version20261112120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'TaskBoardBundle 2.12: task_board_task_time_entries.billable';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE task_board_task_time_entries ADD billable BOOLEAN DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE task_board_task_time_entries DROP billable');
    }
}
