<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Tests\Unit\Import;

use Nowo\TaskBoardBundle\Exception\ImportLimitExceededException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

use function escapeshellarg;
use function shell_exec;
use function trim;

use const PHP_BINARY;

/**
 * Since 1.22.1 (audits DL-L6, EB): the memory PoCs of Appendix EB (files just under the 20 MB upload cap) are refused
 * cleanly with a 128 MB memory_limit, each in its own process — and a normal 5000-row file still parses.
 */
final class ImportMemoryLimitsTest extends TestCase
{
    /**
     * @return iterable<string, array{0: string, 1: string}>
     */
    public static function cases(): iterable
    {
        yield 'EB-L1: one line of commas as the header' => ['wide-header', 'REFUSED ' . ImportLimitExceededException::COLUMNS];
        yield 'EB-L1: one line of commas as a row' => ['wide-row', 'REFUSED ' . ImportLimitExceededException::COLUMNS];
        yield 'EB-L1: one line of tabs' => ['wide-tab', 'REFUSED ' . ImportLimitExceededException::COLUMNS];
        yield 'EB-L1: commas after a plain quote' => ['quoted-row', 'REFUSED ' . ImportLimitExceededException::COLUMNS];
        yield 'EB-L2: 512 columns × 5000 near-empty rows' => ['empty-grid', 'REFUSED ' . ImportLimitExceededException::CELLS];
        yield 'EB-I1: 20 MB of blank lines' => ['blank-lines', 'REFUSED ' . ImportLimitExceededException::ROWS];
        yield 'one 20 MB cell' => ['one-cell', 'REFUSED ' . ImportLimitExceededException::CELL];
        yield 'EB-L3: ClickUp, 6.6 M empty objects' => ['json-clickup', 'REFUSED ' . ImportLimitExceededException::JSON];
        yield 'EB-L3: ClickUp, 10 M integers' => ['json-clickup-ints', 'REFUSED ' . ImportLimitExceededException::JSON];
        yield 'EB-L3: Trello, 1.5 M cards' => ['json-trello', 'REFUSED ' . ImportLimitExceededException::JSON];
        yield 'EB-L3: own format, 6.6 M empty objects' => ['json-taskboard', 'REFUSED ' . ImportLimitExceededException::JSON];
        yield 'EB-L4: a 19 MB cell cut by lone CRs' => ['cr-split-cell', 'REFUSED ' . ImportLimitExceededException::CELL];
        yield 'EB-L4: 960 000 columns cut by lone CRs' => ['cr-split-wide', 'REFUSED ' . ImportLimitExceededException::COLUMNS];
        yield 'EB-L4: a 996 000-column header cut by lone CRs' => ['cr-wide-header', 'REFUSED ' . ImportLimitExceededException::COLUMNS];
        yield 'EB-L5: 2 M short strings' => ['json-strings', 'REFUSED ' . ImportLimitExceededException::JSON];
        yield 'EB-L5: one object of 1.4 M keys' => ['json-keys', 'REFUSED ' . ImportLimitExceededException::JSON];
        yield 'EB-L5: 1.4 M keys in one card' => ['json-keys-nested', 'REFUSED ' . ImportLimitExceededException::JSON];
        yield 'a normal file still parses' => ['normal-csv', 'PARSED 5000'];
        yield 'a normal JSON export still parses' => ['normal-json', 'PARSED 5000'];
    }

    #[DataProvider('cases')]
    public function testRefusedCleanlyAt128Megabytes(string $case, string $expected): void
    {
        $output = (string) shell_exec(escapeshellarg(PHP_BINARY) . ' -d memory_limit=128M ' . escapeshellarg(__DIR__ . '/../../Fixtures/Memory/import-limits.php') . ' ' . escapeshellarg($case) . ' 2>&1');

        self::assertSame($expected, trim($output));
    }
}
