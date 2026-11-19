<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * TaskBoardBundle 2.23 (Beacon spec 242): questions on tasks — four new tables (questions, options, recipients,
 * answers). Additive: nothing existing changes.
 */
final class Version20261118100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'TaskBoardBundle 2.23: task questions';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE task_board_task_question_answers (id VARCHAR(36) NOT NULL, answered_by_name VARCHAR(180) DEFAULT NULL, superseded_at DATETIME DEFAULT NULL, superseded_by_id VARCHAR(36) DEFAULT NULL, answer_number INT NOT NULL, selected_option_ids JSON NOT NULL, other_text LONGTEXT DEFAULT NULL, answered_by_subject VARCHAR(191) NOT NULL COLLATE `utf8mb4_0900_bin`, via VARCHAR(8) NOT NULL, answered_at DATETIME NOT NULL, question_id VARCHAR(36) NOT NULL, answered_by_id INT DEFAULT NULL, INDEX idx_task_board_task_question_answers_question (question_id, answer_number), INDEX IDX_C7EEDECC1E27F6BF (question_id), INDEX IDX_C7EEDECC2FC55A77 (answered_by_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE task_board_task_question_options (id VARCHAR(36) NOT NULL, position SMALLINT NOT NULL, label VARCHAR(120) NOT NULL, description VARCHAR(500) DEFAULT NULL, recommended TINYINT NOT NULL, question_id VARCHAR(36) NOT NULL, UNIQUE INDEX uniq_task_board_task_question_options_position (question_id, position), INDEX IDX_470BE24D1E27F6BF (question_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE task_board_task_question_recipients (id VARCHAR(36) NOT NULL, subject_id VARCHAR(191) NOT NULL COLLATE `utf8mb4_0900_bin`, label VARCHAR(180) NOT NULL, `external` TINYINT NOT NULL, position SMALLINT NOT NULL, question_id VARCHAR(36) NOT NULL, INDEX idx_task_board_task_question_recipients_subject (subject_id), UNIQUE INDEX uniq_task_board_task_question_recipients_subject (question_id, subject_id), INDEX IDX_CB24A4371E27F6BF (question_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE task_board_task_questions (id VARCHAR(36) NOT NULL, state VARCHAR(10) NOT NULL, asked_by_name VARCHAR(180) DEFAULT NULL, current_answer_id VARCHAR(36) DEFAULT NULL, answered_at DATETIME DEFAULT NULL, withdrawn_by_name VARCHAR(180) DEFAULT NULL, withdrawn_at DATETIME DEFAULT NULL, withdraw_reason VARCHAR(500) DEFAULT NULL, published_url VARCHAR(2048) DEFAULT NULL, published_label VARCHAR(180) DEFAULT NULL, published_at DATETIME DEFAULT NULL, version INT DEFAULT 1 NOT NULL, question_text VARCHAR(500) NOT NULL, context LONGTEXT DEFAULT NULL, choice_mode VARCHAR(8) NOT NULL, allow_other TINYINT NOT NULL, asked_at DATETIME NOT NULL, due_on DATE DEFAULT NULL, task_id VARCHAR(36) NOT NULL, asked_by_id INT DEFAULT NULL, withdrawn_by_id INT DEFAULT NULL, INDEX idx_task_board_task_questions_task_state (task_id, state), INDEX idx_task_board_task_questions_state_due (state, due_on), INDEX IDX_3FEF3BF38DB60186 (task_id), INDEX IDX_3FEF3BF34F7A72E4 (asked_by_id), INDEX IDX_3FEF3BF389BE4D2E (withdrawn_by_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE task_board_task_question_answers ADD CONSTRAINT FK_C7EEDECC1E27F6BF FOREIGN KEY (question_id) REFERENCES task_board_task_questions (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE task_board_task_question_answers ADD CONSTRAINT FK_C7EEDECC2FC55A77 FOREIGN KEY (answered_by_id) REFERENCES users (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE task_board_task_question_options ADD CONSTRAINT FK_470BE24D1E27F6BF FOREIGN KEY (question_id) REFERENCES task_board_task_questions (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE task_board_task_question_recipients ADD CONSTRAINT FK_CB24A4371E27F6BF FOREIGN KEY (question_id) REFERENCES task_board_task_questions (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE task_board_task_questions ADD CONSTRAINT FK_3FEF3BF38DB60186 FOREIGN KEY (task_id) REFERENCES task_board_tasks (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE task_board_task_questions ADD CONSTRAINT FK_3FEF3BF34F7A72E4 FOREIGN KEY (asked_by_id) REFERENCES users (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE task_board_task_questions ADD CONSTRAINT FK_3FEF3BF389BE4D2E FOREIGN KEY (withdrawn_by_id) REFERENCES users (id) ON DELETE SET NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE task_board_task_question_answers DROP FOREIGN KEY FK_C7EEDECC1E27F6BF');
        $this->addSql('ALTER TABLE task_board_task_question_options DROP FOREIGN KEY FK_470BE24D1E27F6BF');
        $this->addSql('ALTER TABLE task_board_task_question_recipients DROP FOREIGN KEY FK_CB24A4371E27F6BF');
        $this->addSql('DROP TABLE task_board_task_question_answers');
        $this->addSql('DROP TABLE task_board_task_question_recipients');
        $this->addSql('DROP TABLE task_board_task_question_options');
        $this->addSql('DROP TABLE task_board_task_questions');
    }
}
