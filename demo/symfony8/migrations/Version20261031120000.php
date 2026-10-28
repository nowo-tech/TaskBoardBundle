<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * TaskBoardBundle 1.15: task start date.
 */
final class Version20261031120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'TaskBoardBundle 1.15: task_board_tasks.start_at';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE task_board_tasks ADD start_at DATETIME DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE task_board_tasks DROP start_at');
    }
}
