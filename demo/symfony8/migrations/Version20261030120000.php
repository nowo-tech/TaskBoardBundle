<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * TaskBoardBundle 1.14: task colour.
 */
final class Version20261030120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'TaskBoardBundle 1.14: task_board_tasks.color';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE task_board_tasks ADD color VARCHAR(7) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE task_board_tasks DROP color');
    }
}
