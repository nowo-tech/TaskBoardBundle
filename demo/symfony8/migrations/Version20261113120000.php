<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * TaskBoardBundle 2.16 (Beacon spec 234): the humans-only flag of a column and the optimistic-lock revision of a task.
 * Both with a default: existing columns allow every move as before, existing tasks start at revision 1. Nothing is
 * rewritten or dropped.
 */
final class Version20261113120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'TaskBoardBundle 2.16: task_board_columns.humans_only, task_board_tasks.revision';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE task_board_columns ADD humans_only BOOLEAN DEFAULT false NOT NULL');
        $this->addSql('ALTER TABLE task_board_tasks ADD revision INT DEFAULT 1 NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE task_board_tasks DROP revision');
        $this->addSql('ALTER TABLE task_board_columns DROP humans_only');
    }
}
