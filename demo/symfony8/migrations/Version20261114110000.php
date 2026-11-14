<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * TaskBoardBundle 2.18 (Beacon spec 234, FR-033 / FR-034): column types and a folder's shared status set. Two nullable
 * columns; a column without a type keeps its meaning by position, so nothing is rewritten.
 */
final class Version20261114110000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'TaskBoardBundle 2.18: task_board_columns.column_type, task_board_folders.status_set';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE task_board_columns ADD column_type VARCHAR(16) DEFAULT NULL');
        $this->addSql('ALTER TABLE task_board_folders ADD status_set JSON DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE task_board_folders DROP status_set');
        $this->addSql('ALTER TABLE task_board_columns DROP column_type');
    }
}
