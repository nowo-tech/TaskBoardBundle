<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Tests\Functional\Question;

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Platforms\MariaDBPlatform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\ORM\Tools\SchemaTool;
use Nowo\TaskBoardBundle\Tests\Functional\FunctionalTestCase;

use function str_contains;
use function str_starts_with;
use function stripos;

/**
 * Since 2.23.1 (audit UG-4): quoting `external` in the mapping does not change the column. A recipients table created
 * with the 2.23.0 DDL (unquoted, as the 2.23.0 migration made it on PostgreSQL and MySQL 8) gives a schema update with
 * nothing about that column. MySQL 9 never had that table (EXTERNAL is reserved there); MariaDB's DDL differs (collation).
 */
final class RecipientsColumnQuotingTest extends FunctionalTestCase
{
    protected function setUp(): void
    {
        self::$taskBoardConfig = ['task_questions' => ['enabled' => true]];
        parent::setUp();
    }

    public function testATableCreatedBy2230NeedsNoChangeForTheQuotedColumn(): void
    {
        $connection = $this->em->getConnection();
        $platform   = $connection->getDatabasePlatform();
        if ($platform instanceof PostgreSQLPlatform) {
            $ddl = [
                'DROP TABLE task_board_task_question_recipients',
                'CREATE TABLE task_board_task_question_recipients (id VARCHAR(36) NOT NULL, subject_id VARCHAR(191) NOT NULL, label VARCHAR(180) NOT NULL, external BOOLEAN NOT NULL, position SMALLINT NOT NULL, question_id VARCHAR(36) NOT NULL, PRIMARY KEY (id))',
                'CREATE INDEX idx_task_board_task_question_recipients_subject ON task_board_task_question_recipients (subject_id)',
                'CREATE UNIQUE INDEX uniq_task_board_task_question_recipients_subject ON task_board_task_question_recipients (question_id, subject_id)',
                'CREATE INDEX IDX_CB24A4371E27F6BF ON task_board_task_question_recipients (question_id)',
                'ALTER TABLE task_board_task_question_recipients ADD CONSTRAINT FK_CB24A4371E27F6BF FOREIGN KEY (question_id) REFERENCES task_board_task_questions (id) ON DELETE CASCADE NOT DEFERRABLE',
            ];
        } elseif ($platform instanceof AbstractMySQLPlatform && !$platform instanceof MariaDBPlatform && str_starts_with((string) $connection->fetchOne('SELECT VERSION()'), '8.')) {
            $ddl = [
                'DROP TABLE task_board_task_question_recipients',
                'CREATE TABLE task_board_task_question_recipients (id VARCHAR(36) NOT NULL, subject_id VARCHAR(191) NOT NULL COLLATE `utf8mb4_0900_bin`, label VARCHAR(180) NOT NULL, external TINYINT NOT NULL, position SMALLINT NOT NULL, question_id VARCHAR(36) NOT NULL, INDEX idx_task_board_task_question_recipients_subject (subject_id), UNIQUE INDEX uniq_task_board_task_question_recipients_subject (question_id, subject_id), INDEX IDX_CB24A4371E27F6BF (question_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4',
                'ALTER TABLE task_board_task_question_recipients ADD CONSTRAINT FK_CB24A4371E27F6BF FOREIGN KEY (question_id) REFERENCES task_board_task_questions (id) ON DELETE CASCADE',
            ];
        } else {
            self::markTestSkipped('PostgreSQL or MySQL 8 only (the 2.23.0 DDL of those servers).');
        }
        foreach ($ddl as $statement) {
            $connection->executeStatement($statement);
        }

        $update = (new SchemaTool($this->em))->getUpdateSchemaSql($this->em->getMetadataFactory()->getAllMetadata());
        foreach ($update as $sql) {
            self::assertFalse(str_contains($sql, 'task_board_task_question_recipients') && stripos($sql, 'external') !== false, $sql);
        }
        $connection->executeStatement('INSERT INTO task_board_task_question_recipients (id, subject_id, label, ' . $platform->quoteSingleIdentifier('external') . ', position, question_id) SELECT ?, ?, ?, ?, ?, id FROM task_board_task_questions WHERE 1 = 0', ['x', 'user:1', 'Bob', 1, 0]);
    }
}
