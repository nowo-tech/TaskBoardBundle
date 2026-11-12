<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Tests\Unit\MultiBoard;

use Nowo\ControllerKitBundle\Http\CsvSafeWriter;
use Nowo\TaskBoardBundle\MultiBoard\Export\MultiBoardExportTable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Since 2.13.0 (audit NI-6): the export's own guard before ControllerKit's: a formula trigger behind whitespace, and at
 * the start of every joined part.
 */
#[CoversClass(MultiBoardExportTable::class)]
final class MultiBoardExportGuardTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string}>
     */
    public static function cells(): iterable
    {
        yield 'plain' => ['Homepage', 'Homepage'];
        yield 'leading formula' => ['=1+1', "'=1+1"];
        yield 'leading space' => [' =1+1', " '=1+1"];
        yield 'leading spaces' => ['  +SUM(A1)', "  '+SUM(A1)"];
        yield 'joined with comma and space' => ['Ann, =cmd', "Ann, '=cmd"];
        yield 'joined with semicolon' => ['Ann;@x', "Ann;'@x"];
        yield 'after a line break' => ["a\n -1", "a\n '-1"];
        yield 'inside a word' => ['e-mail a=b', 'e-mail a=b'];
    }

    #[DataProvider('cells')]
    public function testTheGuard(string $text, string $guarded): void
    {
        self::assertSame($guarded, MultiBoardExportTable::guard($text));
        self::assertSame($guarded, CsvSafeWriter::cell($guarded), 'ControllerKit leaves a guarded cell as it is');
    }
}
