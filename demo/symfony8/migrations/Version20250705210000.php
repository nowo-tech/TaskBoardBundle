<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20250705210000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add task change history table';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE task_board_task_change_history (
            id VARCHAR(36) NOT NULL,
            task_id VARCHAR(36) NOT NULL,
            user_id INT NOT NULL,
            change_type VARCHAR(32) NOT NULL,
            old_value LONGTEXT DEFAULT NULL,
            new_value LONGTEXT DEFAULT NULL,
            context VARCHAR(255) DEFAULT NULL,
            created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\',
            INDEX task_board_change_history_task_idx (task_id),
            INDEX IDX_TASK_BOARD_CHANGE_USER (user_id),
            PRIMARY KEY(id),
            CONSTRAINT FK_TASK_BOARD_CHANGE_TASK FOREIGN KEY (task_id) REFERENCES task_board_tasks (id) ON DELETE CASCADE,
            CONSTRAINT FK_TASK_BOARD_CHANGE_USER FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE task_board_task_change_history');
    }
}
