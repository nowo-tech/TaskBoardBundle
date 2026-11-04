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
 * Since 1.22.1 (audit EB-L6): JSON imports posted through the real import controller at a 128 MB memory_limit, each in
 * its own process, so the request's own memory counts. Lists that grow past a power of two are refused cleanly (their
 * weight counts the doubled tables), and the realistic export the default `max_json_nodes` takes still imports.
 */
final class ImportControllerMemoryTest extends TestCase
{
    /**
     * @return iterable<string, array{0: string, 1: string}>
     */
    public static function cases(): iterable
    {
        yield 'a card list of 2^22 + 1 zeros' => ['list-2^22+1', '/^HTTP 302 tasks=0 The JSON file is too large/'];
        yield 'two lists of 2^21 + 1 zeros' => ['two-lists-2^21+1', '/^HTTP 302 tasks=0 The JSON file is too large/'];
        yield 'a ClickUp list of 2^22 + 1 zeros' => ['clickup-zeros', '/^HTTP 302 tasks=0 The JSON file is too large/'];
        yield '1200 realistic ClickUp tasks import' => ['realistic-1200', '/^HTTP 302 tasks=1200 /'];
        yield '1250 realistic ClickUp tasks are refused' => ['realistic-1250', '/^HTTP 302 tasks=0 The JSON file is too large/'];
    }

    #[DataProvider('cases')]
    public function testCleanAt128Megabytes(string $case, string $expected): void
    {
        $output = trim((string) shell_exec(escapeshellarg(PHP_BINARY) . ' -d upload_max_filesize=25M -d post_max_size=30M ' . escapeshellarg(__DIR__ . '/../../Fixtures/Memory/import-controller.php') . ' ' . escapeshellarg($case) . ' 128M 2>&1'));

        self::assertMatchesRegularExpression($expected, $output);
    }
}
