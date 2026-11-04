<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Tests\Unit\Import;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

use function escapeshellarg;
use function shell_exec;
use function trim;

use const PHP_BINARY;

/**
 * Since 1.22.1 (audit EB-L6): imports posted through the real import controller at a 128 MB memory_limit, each in its
 * own process, so the request's own memory counts. Lists that grow past a power of two are refused cleanly (their
 * weight counts the doubled tables), and the realistic export the default `max_json_nodes` takes still imports.
 * Since 1.22.2 (audit EB-L7): 5000 rows (the default max_rows) of CSV, Trello JSON and the own format import at
 * 128 MB, and an import that fails in its middle leaves nothing behind.
 */
final class ImportControllerMemoryTest extends TestCase
{
    /**
     * @return iterable<string, array{0: string, 1: string}>
     */
    public static function cases(): iterable
    {
        yield 'a card list of 2^22 + 1 zeros' => ['list-2^22+1', '/^HTTP 302 tasks=0 links=0 history=0 The JSON file is too large/'];
        yield 'two lists of 2^21 + 1 zeros' => ['two-lists-2^21+1', '/^HTTP 302 tasks=0 links=0 history=0 The JSON file is too large/'];
        yield 'a ClickUp list of 2^22 + 1 zeros' => ['clickup-zeros', '/^HTTP 302 tasks=0 links=0 history=0 The JSON file is too large/'];
        yield '2700 realistic ClickUp tasks import' => ['realistic-2700', '/^HTTP 302 tasks=2700 /'];
        yield '2750 realistic ClickUp tasks are refused' => ['realistic-2750', '/^HTTP 302 tasks=0 links=0 history=0 The JSON file is too large/'];
        yield 'EB-L7: 5000 CSV rows import' => ['csv-5000', '/^HTTP 302 tasks=5000 links=5000 history=5000 /'];
        yield 'EB-L7: 5000 Trello cards import' => ['trello-5000', '/^HTTP 302 tasks=5000 links=5000 history=5000 /'];
        yield 'EB-L7: 5000 own-format tasks import' => ['own-5000', '/^HTTP 302 tasks=5000 links=5000 history=5000 /'];
        yield 'EB-L7: a failure in the middle leaves nothing behind' => ['csv-failure', '/^HTTP 500 tasks=0 links=0 history=0/'];
    }

    #[DataProvider('cases')]
    public function testCleanAt128Megabytes(string $case, string $expected): void
    {
        self::assertMatchesRegularExpression($expected, $this->runCase($case, '128M'));
    }

    /** The documented settings for a 5000-task ClickUp API export: max_json_nodes 7 500 000 and memory_limit 256M. */
    public function testA5000TaskClickUpExportImportsWithTheDocumentedSettings(): void
    {
        self::assertMatchesRegularExpression('/^HTTP 302 tasks=5000 /', $this->runCase('clickup-5000-raised', '256M'));
    }

    private function runCase(string $case, string $memoryLimit): string
    {
        return trim((string) shell_exec(escapeshellarg(PHP_BINARY) . ' -d upload_max_filesize=25M -d post_max_size=30M ' . escapeshellarg(__DIR__ . '/../../Fixtures/Memory/import-controller.php') . ' ' . escapeshellarg($case) . ' ' . escapeshellarg($memoryLimit) . ' 2>&1'));
    }
}
