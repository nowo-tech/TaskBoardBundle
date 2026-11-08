<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * TaskBoardBundle 2.3 (spec 216, FR-013): why a history row happened. Every host adds this nullable column.
 */
final class Version20261108120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'TaskBoardBundle 2.3: task_board_task_change_history.cause';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE task_board_task_change_history ADD cause VARCHAR(191) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE task_board_task_change_history DROP cause');
    }
}
