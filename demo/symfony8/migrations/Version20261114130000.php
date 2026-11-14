<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * TaskBoardBundle 2.19 (Beacon block W): a board's numbering prefix (`AIF-12` instead of `#12`). One nullable column.
 */
final class Version20261114130000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'TaskBoardBundle 2.19: task_board_boards.number_prefix';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE task_board_boards ADD number_prefix VARCHAR(16) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE task_board_boards DROP number_prefix');
    }
}
