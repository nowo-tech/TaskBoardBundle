<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * TaskBoardBundle 2.18 (Beacon spec 234): write provenance. Nullable columns only: the channel and actor label of every
 * history row, and the last writer, channel and actor label of tasks and comments. Existing rows keep NULL; nothing is
 * rewritten or dropped. MySQL / MariaDB (the demo's database); see UPGRADING for PostgreSQL, SQLite and user_reference: id.
 */
final class Version20261114120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'TaskBoardBundle 2.18: channel, actor_label (history, tasks, comments), updated_by_id (tasks, comments)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE task_board_task_change_history ADD channel VARCHAR(8) DEFAULT NULL, ADD actor_label VARCHAR(120) DEFAULT NULL');
        $this->addSql('ALTER TABLE task_board_tasks ADD updated_by_id INT DEFAULT NULL, ADD channel VARCHAR(8) DEFAULT NULL, ADD actor_label VARCHAR(120) DEFAULT NULL');
        $this->addSql('ALTER TABLE task_board_tasks ADD CONSTRAINT FK_B4902F8E896DBBDE FOREIGN KEY (updated_by_id) REFERENCES users (id) ON DELETE SET NULL');
        $this->addSql('CREATE INDEX IDX_B4902F8E896DBBDE ON task_board_tasks (updated_by_id)');
        $this->addSql('ALTER TABLE task_board_task_comments ADD updated_by_id INT DEFAULT NULL, ADD channel VARCHAR(8) DEFAULT NULL, ADD actor_label VARCHAR(120) DEFAULT NULL');
        $this->addSql('ALTER TABLE task_board_task_comments ADD CONSTRAINT FK_BC3E8197896DBBDE FOREIGN KEY (updated_by_id) REFERENCES users (id) ON DELETE SET NULL');
        $this->addSql('CREATE INDEX IDX_BC3E8197896DBBDE ON task_board_task_comments (updated_by_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE task_board_task_comments DROP FOREIGN KEY FK_BC3E8197896DBBDE');
        $this->addSql('DROP INDEX IDX_BC3E8197896DBBDE ON task_board_task_comments');
        $this->addSql('ALTER TABLE task_board_task_comments DROP updated_by_id, DROP channel, DROP actor_label');
        $this->addSql('ALTER TABLE task_board_tasks DROP FOREIGN KEY FK_B4902F8E896DBBDE');
        $this->addSql('DROP INDEX IDX_B4902F8E896DBBDE ON task_board_tasks');
        $this->addSql('ALTER TABLE task_board_tasks DROP updated_by_id, DROP channel, DROP actor_label');
        $this->addSql('ALTER TABLE task_board_task_change_history DROP channel, DROP actor_label');
    }
}
