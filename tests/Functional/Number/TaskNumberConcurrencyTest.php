<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Tests\Functional\Number;

use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Tools\DsnParser;
use Nowo\TaskBoardBundle\Number\Entity\TaskNumberCounter;
use Nowo\TaskBoardBundle\Tests\Functional\App\FunctionalKernel;
use PHPUnit\Framework\TestCase;

use function array_filter;
use function array_map;
use function array_merge;
use function array_unique;
use function count;
use function explode;
use function file_exists;
use function proc_close;
use function proc_open;
use function sort;
use function str_starts_with;
use function stream_get_contents;
use function sys_get_temp_dir;
use function tempnam;
use function unlink;

use const PHP_BINARY;

/**
 * Spec 214, SC-001: two processes reserving in one scope, the first time included, never get the same number and leave no hole.
 * Against TASK_BOARD_TEST_DATABASE_URL (MySQL and PostgreSQL in CI), else a SQLite file.
 */
final class TaskNumberConcurrencyTest extends TestCase
{
    private const SCOPE = 'concurrency';

    public function testTwoProcessesNeverGetTheSameNumber(): void
    {
        $url  = FunctionalKernel::databaseUrl();
        $file = null;
        if (str_starts_with($url, 'sqlite')) {
            $file = tempnam(sys_get_temp_dir(), 'tb-numbers');
            $url  = 'sqlite:///' . $file;
        }
        $db = DriverManager::getConnection((new DsnParser(['mysql' => 'pdo_mysql', 'postgresql' => 'pdo_pgsql', 'sqlite' => 'pdo_sqlite']))->parse($url));
        // The functional tests manage their own schema: this one needs only the counter, and starts without its row.
        $db->executeStatement('CREATE TABLE IF NOT EXISTS ' . TaskNumberCounter::TABLE . ' (scope_key VARCHAR(191) NOT NULL PRIMARY KEY, last_number INT NOT NULL)');
        $db->executeStatement('DELETE FROM ' . TaskNumberCounter::TABLE . ' WHERE scope_key = ?', [self::SCOPE]);

        try {
            $numbers = [];
            $running = [];
            foreach ([1, 2, 3] as $worker) {
                $process = proc_open([PHP_BINARY, __DIR__ . '/reserve-worker.php', $url, self::SCOPE, '40'], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
                self::assertIsResource($process);
                $running[$worker] = [$process, $pipes];
            }
            foreach ($running as [$process, $pipes]) {
                $output = (string) stream_get_contents($pipes[1]);
                $errors = (string) stream_get_contents($pipes[2]);
                self::assertSame(0, proc_close($process), $errors);
                $numbers = array_merge($numbers, array_map(intval(...), array_filter(explode("\n", $output))));
            }

            sort($numbers);
            // 40 reservations, 8 of them for two numbers, in each of three processes.
            self::assertSame(144, count($numbers));
            self::assertSame($numbers, array_unique($numbers), 'A number was handed out twice.');
            self::assertSame(144, $numbers[143]);
        } finally {
            $db->executeStatement('DELETE FROM ' . TaskNumberCounter::TABLE . ' WHERE scope_key = ?', [self::SCOPE]);
            $db->close();
            if ($file !== null && file_exists($file)) {
                unlink($file);
            }
        }
    }
}
