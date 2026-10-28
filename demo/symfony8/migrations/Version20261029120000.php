<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * TaskBoardBundle 1.11: column icon and column sets.
 */
final class Version20261029120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'TaskBoardBundle 1.11: task_board_columns.icon, task_board_column_sets';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE task_board_columns ADD icon VARCHAR(64) DEFAULT NULL');
        $this->addSql('CREATE TABLE task_board_column_sets (
            id VARCHAR(36) NOT NULL,
            created_by_id INT DEFAULT NULL,
            name VARCHAR(128) NOT NULL,
            scope_key VARCHAR(191) DEFAULT NULL,
            items JSON NOT NULL,
            created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\',
            INDEX idx_task_board_column_sets_scope (scope_key),
            INDEX IDX_TASK_BOARD_COLUMN_SET_CREATOR (created_by_id),
            PRIMARY KEY(id),
            CONSTRAINT FK_TASK_BOARD_COLUMN_SET_CREATOR FOREIGN KEY (created_by_id) REFERENCES users (id) ON DELETE SET NULL
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE task_board_column_sets');
        $this->addSql('ALTER TABLE task_board_columns DROP icon');
    }
}
