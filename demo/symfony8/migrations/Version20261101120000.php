<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * TaskBoardBundle 1.16: task comments.
 */
final class Version20261101120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'TaskBoardBundle 1.16: task_board_task_comments';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE task_board_task_comments (id VARCHAR(36) NOT NULL, created_at DATETIME NOT NULL, edited_at DATETIME DEFAULT NULL, deleted_at DATETIME DEFAULT NULL, mentions JSON NOT NULL, body LONGTEXT NOT NULL, task_id VARCHAR(36) NOT NULL, author_id INT NOT NULL, INDEX IDX_BC3E8197F675F31B (author_id), INDEX task_board_task_comments_task_idx (task_id, created_at), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE task_board_task_comments ADD CONSTRAINT FK_BC3E81978DB60186 FOREIGN KEY (task_id) REFERENCES task_board_tasks (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE task_board_task_comments ADD CONSTRAINT FK_BC3E8197F675F31B FOREIGN KEY (author_id) REFERENCES users (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE task_board_task_comments');
    }
}
