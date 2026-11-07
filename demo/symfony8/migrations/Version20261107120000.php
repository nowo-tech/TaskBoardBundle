<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * TaskBoardBundle 2.1 (spec 214): the task number, its counter and its ledger (the demo enables them).
 */
final class Version20261107120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'TaskBoardBundle 2.1: task numbers';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE task_board_tasks ADD number INT DEFAULT NULL');
        $this->addSql('CREATE INDEX task_board_tasks_number_idx ON task_board_tasks (number)');
        $this->addSql('CREATE TABLE task_board_number_counters (scope_key VARCHAR(191) NOT NULL, last_number INT NOT NULL, PRIMARY KEY (scope_key)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE task_board_task_numbers (scope_key VARCHAR(191) NOT NULL, number INT NOT NULL, task_id VARCHAR(36) DEFAULT NULL, INDEX idx_task_board_task_numbers_task (task_id), PRIMARY KEY (scope_key, number)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE task_board_task_numbers ADD CONSTRAINT fk_task_board_task_numbers_task FOREIGN KEY (task_id) REFERENCES task_board_tasks (id) ON DELETE SET NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE task_board_task_numbers DROP FOREIGN KEY fk_task_board_task_numbers_task');
        $this->addSql('DROP TABLE task_board_task_numbers');
        $this->addSql('DROP TABLE task_board_number_counters');
        $this->addSql('DROP INDEX task_board_tasks_number_idx ON task_board_tasks');
        $this->addSql('ALTER TABLE task_board_tasks DROP number');
    }
}
