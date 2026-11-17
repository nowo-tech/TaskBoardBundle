<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Tests\Functional\Api;

use Doctrine\DBAL\Platforms\SQLitePlatform;
use Nowo\TaskBoardBundle\Entity\BoardColumn;
use Nowo\TaskBoardBundle\Entity\TaskBoard;
use Nowo\TaskBoardBundle\Tests\Functional\Api\App\ApiTestHost;
use Symfony\Bridge\Doctrine\Middleware\Debug\DebugDataHolder;

use function array_values;
use function fgets;
use function is_resource;
use function preg_match;
use function proc_close;
use function proc_open;
use function stream_get_contents;

use const PHP_BINARY;

/**
 * Beacon spec 239, audit SF-M1: a Task API update that holds its tasks' rows (a bulk update, an update with a base
 * revision) and moves one of them into another column locks that column first, as every other write does (columns before
 * tasks). Before, it locked the task (`lockedRevision()`) and then the column, the reverse order, and deadlocked against a
 * move that renumbers that column (probe P1: MySQL 1213, PostgreSQL 40P01).
 */
final class ApiLockOrderTest extends ApiTestCase
{
    private TaskBoard $board;

    protected function setUp(): void
    {
        parent::setUp();
        ApiTestHost::$tenants = ['tenant-a'];
        $this->board          = $this->board('Roadmap', ['To do', 'Doing', 'Done'], tenantKey: 'tenant-a');
        $this->issueToken();
    }

    public function testABulkUpdateLocksTheColumnsItEntersBeforeItsTasks(): void
    {
        $task = $this->task($this->board, 'Bulk', 0);

        $queries = $this->logged(fn () => $this->send('POST', '/api/tasks/v2/task/bulk', ['tasks' => [['id' => $task->getId(), 'base_revision' => 1, 'status' => 'Doing']]]));

        self::assertResponseIsSuccessful();
        $this->assertColumnBeforeTask($queries, $this->column(1)->getId());
    }

    public function testALockedUpdateLocksTheColumnItEntersBeforeTheTask(): void
    {
        $task = $this->task($this->board, 'Locked', 0);

        $queries = $this->logged(fn () => $this->send('PUT', '/api/tasks/v2/task/' . $task->getId(), ['status' => 'Done'], ['HTTP_IF_MATCH' => '"1"']));

        self::assertResponseIsSuccessful();
        $this->assertColumnBeforeTask($queries, $this->column(2)->getId());
    }

    public function testAnUnknownStatusIsStillRefusedInItsPlace(): void
    {
        $task  = $this->task($this->board, 'Unknown', 0);
        $other = $this->task($this->board, 'Known', 0);

        $this->send('POST', '/api/tasks/v2/task/bulk', ['tasks' => [['id' => $task->getId(), 'status' => 'Nowhere'], ['id' => $other->getId(), 'status' => 'Doing']]]);

        self::assertResponseStatusCodeSame(409);
        self::assertSame('BULK_001', $this->json()['ECODE'] ?? null);
        self::assertSame('INPUT_002', $this->json()['failures'][0]['ECODE'] ?? null);
    }

    public function testABulkUpdateAndAConcurrentRenumberingMoveBothFinish(): void
    {
        // Probe P1: X is in Doing (the bulk update locks its row), Y enters Doing; a move of Z holds Doing and renumbers it.
        if ($this->em->getConnection()->getDatabasePlatform() instanceof SQLitePlatform) {
            self::markTestSkipped('SQLite serialises writers: there is no second connection to cross.');
        }
        $x     = $this->task($this->board, 'X in Doing', 1, position: 0);
        $z     = $this->task($this->board, 'Z in Doing', 1, position: 1);
        $y     = $this->task($this->board, 'Y in To do', 0);
        $doing = $this->column(1);

        $process = proc_open([PHP_BINARY, __DIR__ . '/../../Fixtures/Deadlock/move_worker.php', 'move', $z->getId(), $doing->getId(), '0', '1000000'], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        self::assertTrue(is_resource($process));
        self::assertSame("locked\n", fgets($pipes[1]), 'the move holds the column');
        $this->send('POST', '/api/tasks/v2/task/bulk', ['tasks' => [['id' => $x->getId(), 'base_revision' => 1, 'description' => 'Edited'], ['id' => $y->getId(), 'base_revision' => 1, 'status' => 'Doing']]]);
        $status = $this->client->getResponse()->getStatusCode();
        $body   = (string) $this->client->getResponse()->getContent();
        $other  = (string) stream_get_contents($pipes[1]);
        $errors = (string) stream_get_contents($pipes[2]);
        self::assertSame(0, proc_close($process), $errors);

        self::assertSame('moved', $other, 'the move was not chosen as a deadlock victim');
        self::assertSame(200, $status, $body);
        $positions = $this->em->getConnection()->fetchFirstColumn('SELECT position FROM task_board_tasks WHERE column_id = ? ORDER BY position', [$doing->getId()]);
        self::assertSame([0, 1, 2], array_map('intval', $positions), 'unique and contiguous');
    }

    private function column(int $index): BoardColumn
    {
        $column = $this->board->getColumns()->get($index);
        self::assertInstanceOf(BoardColumn::class, $column);

        return $column;
    }

    /**
     * @param list<string> $queries
     */
    private function assertColumnBeforeTask(array $queries, string $columnId): void
    {
        $column = null;
        $task   = null;
        foreach ($queries as $index => $sql) {
            if ($column === null && preg_match('/^SELECT \w+\.id AS \w+ FROM task_board_columns \w+ WHERE \w+\.id IN/', $sql) === 1) {
                $column = $index;
            }
            if ($task === null && preg_match('/^SELECT \w+\.revision AS revision/', $sql) === 1) {
                $task = $index;
            }
        }
        self::assertNotNull($column, 'the column ' . $columnId . ' is locked');
        self::assertNotNull($task, 'the task\'s revision is read locked');
        self::assertLessThan($task, $column, 'the column before the task');
    }

    /**
     * @param callable(): mixed $request
     *
     * @return list<string> the SQL of the request, in order
     */
    private function logged(callable $request): array
    {
        $holder = self::getContainer()->get('doctrine.debug_data_holder');
        self::assertInstanceOf(DebugDataHolder::class, $holder);
        $holder->reset();
        $request();
        $queries = [];
        foreach ($holder->getData() as $connectionQueries) {
            foreach ($connectionQueries as $query) {
                $queries[] = (string) $query['sql'];
            }
        }

        return array_values($queries);
    }
}
