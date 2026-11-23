<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Tests\Functional;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use Doctrine\DBAL\Tools\DsnParser;
use Nowo\TaskBoardBundle\Doctrine\LockConflictChain;
use Nowo\TaskBoardBundle\Dto\TaskFormData;
use Nowo\TaskBoardBundle\Entity\Task;
use Nowo\TaskBoardBundle\Entity\TaskBoard;
use Nowo\TaskBoardBundle\Exception\TaskChangedException;
use Nowo\TaskBoardBundle\Service\TaskManagerInterface;
use Nowo\TaskBoardBundle\Support\TaskContentDigest;
use Nowo\TaskBoardBundle\Tests\Functional\App\FunctionalKernel;
use Nowo\TaskBoardBundle\Tests\Functional\App\ScriptedTransitionPolicy;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\DomCrawler\Crawler;
use Throwable;

use function fgets;
use function proc_close;
use function proc_open;
use function stream_get_contents;
use function trim;

use const PHP_BINARY;

/**
 * Since 2.31.0 (REQ-RUNTIME-003 rule 4): two people saving the task edit form of one task. The form posts the content
 * digest of the task it showed (`baseDigest`); a save whose task someone else changed meanwhile is refused ("The task
 * changed meanwhile: nothing was saved"), never written over the other person's changes. Up to 2.30 the second save
 * silently reverted the first one's title, description, dates….
 *
 * - The other save committed before this request loaded the task (a form left open): refused by the digest.
 * - The other save committed between this request's load and its row lock: refused by the revision under the lock.
 * - The other save holds the task's row (a second process) while this request waits on it: refused once it commits.
 * - A card moved meanwhile (no content change) still saves the edit and leaves the task where it is (2.2.1).
 *
 * The two-connection cases run on MySQL 8.4 / 9, MariaDB 11.4 and PostgreSQL 16 (TASK_BOARD_TEST_DATABASE_URL); the
 * others also on SQLite.
 */
#[Group('database')]
final class TaskEditFormRaceTest extends FunctionalTestCase
{
    private const BASE = '/tools/task-board/';

    private TaskBoard $board;

    private ?Connection $other = null;

    protected function setUp(): void
    {
        self::$taskBoardConfig               = ['column_transition_policy' => 'app.transition_policy'];
        ScriptedTransitionPolicy::$asked     = [];
        ScriptedTransitionPolicy::$meanwhile = null;
        parent::setUp();
        $this->board = $this->board('Race');
        $this->login($this->owner);
    }

    protected function tearDown(): void
    {
        ScriptedTransitionPolicy::$meanwhile = null;
        $this->other?->close();
        $this->other = null;
        parent::tearDown();
    }

    public function testTheFormPostsTheDigestOfTheTaskItShows(): void
    {
        $task    = $this->task($this->board, 'Shown');
        $crawler = $this->client->request('GET', self::BASE . 'task/' . $task->getId());

        self::assertSame(TaskContentDigest::of($task), $crawler->filter('input[name="task_form[baseDigest]"]')->attr('value'));
    }

    public function testASaveOverAnEditCommittedBeforeTheLoadIsRefused(): void
    {
        $task    = $this->task($this->board, 'Original');
        $crawler = $this->client->request('GET', self::BASE . 'task/' . $task->getId());

        // Someone else saves the task while this form is open.
        $this->em->getConnection()->executeStatement('UPDATE task_board_tasks SET title = ?, description = ?, revision = revision + 1 WHERE id = ?', ['Theirs', 'Their notes', $task->getId()]);
        $this->em->clear();

        $this->submitTitle($crawler, 'Mine');

        $this->assertRefusedAndKept($task, 'Theirs', 'Their notes');
    }

    public function testASaveOverAnEditCommittedBetweenTheLoadAndTheLockIsRefused(): void
    {
        $task    = $this->task($this->board, 'Original');
        $crawler = $this->client->request('GET', self::BASE . 'task/' . $task->getId());
        // New tags are asked of the column transition policy (operation `retag`): the other save commits right then,
        // after this request loaded the task and before it locks the row.
        $connection                          = $this->em->getConnection();
        ScriptedTransitionPolicy::$meanwhile = static function () use ($connection, $task): void {
            $connection->executeStatement('UPDATE task_board_tasks SET title = ?, revision = revision + 1 WHERE id = ?', ['Theirs', $task->getId()]);
        };
        $this->em->clear();

        $form                     = $crawler->filter('form[name="task_form"]')->form();
        $form['task_form[title]'] = 'Mine';
        $form['task_form[tags]']  = 'urgent';
        $this->client->submit($form);

        $this->assertRefusedAndKept($task, 'Theirs', null);
    }

    public function testACardMovedMeanwhileStillSavesTheEdit(): void
    {
        $task    = $this->task($this->board, 'Original');
        $crawler = $this->client->request('GET', self::BASE . 'task/' . $task->getId());
        $doing   = $this->board->getColumns()->get(1);
        self::assertNotNull($doing);
        $this->em->getConnection()->executeStatement('UPDATE task_board_tasks SET column_id = ?, revision = revision + 1 WHERE id = ?', [$doing->getId(), $task->getId()]);
        $this->em->clear();

        $this->submitTitle($crawler, 'Mine');

        self::assertResponseRedirects();
        $this->em->clear();
        $kept = $this->em->find(Task::class, $task->getId());
        self::assertInstanceOf(Task::class, $kept);
        self::assertSame(['Mine', 'Doing'], [$kept->getTitle(), $kept->getColumn()?->getName()]);
    }

    public function testASaveWaitingOnARowAnotherProcessHoldsIsRefusedOnceItCommits(): void
    {
        $this->requireServer();
        $task    = $this->task($this->board, 'Original');
        $crawler = $this->client->request('GET', self::BASE . 'task/' . $task->getId());

        // The other save: a second process locks the row, writes its title, holds the lock for a second, then commits.
        $process = proc_open([PHP_BINARY, __DIR__ . '/edit-race-worker.php', FunctionalKernel::databaseUrl(), $task->getId(), 'Theirs', '1.0'], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        self::assertIsResource($process);
        self::assertSame('locked', trim((string) fgets($pipes[1])), (string) stream_get_contents($pipes[2]));

        $this->submitTitle($crawler, 'Mine');

        $output = (string) stream_get_contents($pipes[1]);
        $errors = (string) stream_get_contents($pipes[2]);
        self::assertSame(0, proc_close($process), $output . $errors);
        self::assertSame('committed', trim($output));
        $this->assertRefusedAndKept($task, 'Theirs', null);
        self::assertSame(0, $this->em->getConnection()->getTransactionNestingLevel(), 'no transaction left open');
    }

    public function testARowHeldByAnotherConnectionIsALockWaitThatWritesNothing(): void
    {
        $this->requireServer();
        $task  = $this->task($this->board, 'Original');
        $data  = TaskFormData::fromTask($task);
        $other = $this->other();
        $this->shortLockWait();
        $data->title      = 'Mine';
        $data->baseDigest = TaskContentDigest::of($task);

        $other->beginTransaction();
        $other->fetchOne('SELECT id FROM task_board_tasks WHERE id = ? FOR UPDATE', [$task->getId()]);
        try {
            $this->manager()->update($task, $data, $this->owner);
            self::fail('The held row must not be written.');
        } catch (TaskChangedException $changed) {
            self::fail('A lock wait is not a change: ' . $changed->getMessage());
        } catch (Throwable $failure) {
            self::assertTrue(LockConflictChain::isConflict($failure), $failure::class . ': ' . $failure->getMessage());
        } finally {
            $other->commit();
        }

        self::assertSame(0, $this->em->getConnection()->getTransactionNestingLevel());
        self::assertSame('Original', $this->em->getConnection()->fetchOne('SELECT title FROM task_board_tasks WHERE id = ?', [$task->getId()]));
    }

    public function testATaskDeletedMeanwhileIsRefused(): void
    {
        $task             = $this->task($this->board, 'Gone');
        $data             = TaskFormData::fromTask($task);
        $data->title      = 'Mine';
        $data->baseDigest = TaskContentDigest::of($task);
        $connection       = $this->em->getConnection();
        $connection->executeStatement('DELETE FROM task_board_tasks WHERE id = ?', [$task->getId()]);

        try {
            $this->manager()->update($task, $data, $this->owner);
            self::fail('A deleted task must not be written.');
        } catch (TaskChangedException $changed) {
            self::assertSame(0, $changed->currentRevision);
        }
    }

    private function submitTitle(Crawler $crawler, string $title): void
    {
        $form                     = $crawler->filter('form[name="task_form"]')->form();
        $form['task_form[title]'] = $title;
        $this->client->submit($form);
    }

    private function assertRefusedAndKept(Task $task, string $title, ?string $description): void
    {
        self::assertResponseRedirects();
        $this->client->followRedirect();
        self::assertStringContainsString('The task changed meanwhile: nothing was saved. Try again.', $this->client->getCrawler()->text());
        $row = $this->em->getConnection()->fetchAssociative('SELECT title, description FROM task_board_tasks WHERE id = ?', [$task->getId()]);
        self::assertIsArray($row);
        self::assertSame($title, $row['title'], 'the other person\'s save stays');
        if ($description !== null) {
            self::assertSame($description, $row['description']);
        }
        self::assertSame(0, (int) $this->em->getConnection()->fetchOne("SELECT COUNT(*) FROM task_board_task_change_history WHERE task_id = ? AND change_type = 'title'", [$task->getId()]), 'nothing of the refused save is recorded');
    }

    private function requireServer(): void
    {
        if ($this->em->getConnection()->getDatabasePlatform() instanceof SQLitePlatform) {
            self::markTestSkipped('Two connections need a database server (TASK_BOARD_TEST_DATABASE_URL: MySQL, MariaDB, PostgreSQL).');
        }
    }

    private function shortLockWait(): void
    {
        $connection = $this->em->getConnection();
        $connection->executeStatement($connection->getDatabasePlatform() instanceof PostgreSQLPlatform ? "SET lock_timeout = '1s'" : 'SET SESSION innodb_lock_wait_timeout = 1');
    }

    private function other(): Connection
    {
        return $this->other ??= DriverManager::getConnection((new DsnParser(['mysql' => 'pdo_mysql', 'postgresql' => 'pdo_pgsql']))->parse(FunctionalKernel::databaseUrl()));
    }

    private function manager(): TaskManagerInterface
    {
        return self::getContainer()->get(TaskManagerInterface::class);
    }
}
