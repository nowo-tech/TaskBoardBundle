<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Tests\Unit\Import;

use Nowo\TaskBoardBundle\Exception\ImportLimitExceededException;
use Nowo\TaskBoardBundle\Import\Support\DelimitedTableParser;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

use function count;
use function fclose;
use function fgetcsv;
use function fopen;
use function fwrite;
use function json_encode;
use function max;
use function mt_rand;
use function mt_srand;
use function rewind;
use function str_starts_with;
use function strlen;
use function substr;
use function trim;

/**
 * Since 1.22.1 (audit EB-L4): the pre-pass finds records, quotes and cells exactly as fgetcsv() does — a lone "\r" is
 * part of a cell, and the blanks PHP skips before an opening quote ("\v", "\f", "\r" too) are skipped — and every record
 * fgetcsv() builds is checked again before it is kept.
 */
final class DelimitedTableParserParityTest extends TestCase
{
    private const BIG = 1 << 30;

    /**
     * @return iterable<string, array{0: string, 1: int, 2: int, 3: string}>
     */
    public static function cases(): iterable
    {
        yield 'a lone CR is part of the cell' => ["a\nxxxx\ryyyy", 8, self::BIG, ImportLimitExceededException::CELL];
        yield 'a lone CR does not split the columns' => ["a\n,,\r,,", self::BIG, 4, ImportLimitExceededException::COLUMNS];
    }

    #[DataProvider('cases')]
    public function testALoneCrDoesNotEndARecord(string $csv, int $maxCell, int $maxColumns, string $expected): void
    {
        try {
            (new DelimitedTableParser(maxCellLength: $maxCell, maxColumns: $maxColumns))->parse($csv, 'x.csv');
            self::fail('refused');
        } catch (ImportLimitExceededException $exception) {
            self::assertSame($expected, $exception->translationKey);
        }
    }

    public function testVtAndFfBeforeAQuoteOpenIt(): void
    {
        // For fgetcsv() the quoted cell holds the line break and the commas: one row of one column, which the
        // pre-pass sees the same way (two columns or two rows would be refused here).
        $parser = new DelimitedTableParser(maxRows: 1, maxColumns: 1);
        self::assertSame([['a' => "x\ny,z"]], $parser->parse("a\n\x0B\"x\ny,z\"", 'x.csv'));
        self::assertSame([['a' => 'x,y']], $parser->parse("a\n\x0C\"x,y\"", 'x.csv'));
    }

    public function testACrlfIsOneRecordEndAndItsCrIsNotPartOfTheCell(): void
    {
        self::assertSame([['a' => 'xxxx'], ['a' => 'yyyy']], (new DelimitedTableParser(maxCellLength: 4))->parse("a\r\nxxxx\r\nyyyy", 'x.csv'));
    }

    /**
     * The auditor's differential fuzz (poc/eb/fuzz-parity.php): random short CSVs over the bytes that matter; with each
     * limit set one below what fgetcsv() really builds, the parser must refuse.
     */
    public function testNoRandomCsvBypassesALimit(): void
    {
        $alphabet = ['a', 'a', 'b', ',', ',', '"', '"', "\r", "\n", ' ', "\t", ';', "\x0B", "\x0C", "\r\n", "\xEF\xBB\xBF"];
        $bypasses = [];
        foreach ([11, 12, 13] as $seed) {
            mt_srand($seed);
            for ($n = 0; $n < 4000; ++$n) {
                $csv = '';
                for ($k = mt_rand(1, 30); $k > 0; --$k) {
                    $csv .= $alphabet[mt_rand(0, count($alphabet) - 1)];
                }
                $shape = $this->shape($csv);
                if ($shape === null) {
                    continue;
                }
                foreach ([
                    'columns' => [$shape['columns'] - 1, self::BIG, self::BIG],
                    'cell'    => [self::BIG, $shape['cell'] - 1, self::BIG],
                    'cells'   => [self::BIG, self::BIG, $shape['cells'] - 1],
                ] as $what => [$maxColumns, $maxCell, $maxCells]) {
                    if ($maxColumns < 1 || $maxCell < 1 || $maxCells < 1) {
                        continue;
                    }
                    try {
                        (new DelimitedTableParser(maxRows: self::BIG, maxCellLength: $maxCell, maxColumns: $maxColumns, maxCells: $maxCells))->parse($csv, 'x.csv');
                        $bypasses[] = $what . ' ' . json_encode($csv);
                    } catch (ImportLimitExceededException) {
                    }
                }
            }
        }

        self::assertSame([], $bypasses);
    }

    public function testEachRecordIsCheckedAgainAfterFgetcsv(): void
    {
        $checked = new ReflectionMethod(DelimitedTableParser::class, 'checked');
        $parser  = new DelimitedTableParser(maxCellLength: 3, maxColumns: 2);
        self::assertSame(['abc', null], $checked->invoke($parser, ['abc', null]));
        foreach ([[['a', 'b', 'c'], ImportLimitExceededException::COLUMNS], [['abcd'], ImportLimitExceededException::CELL]] as [$record, $key]) {
            try {
                $checked->invoke($parser, $record);
                self::fail($key);
            } catch (ImportLimitExceededException $exception) {
                self::assertSame($key, $exception->translationKey);
            }
        }
    }

    /**
     * What fgetcsv() builds from the parser's own preprocessing: the widest record, the longest cell and the data
     * cells; null for an empty file.
     *
     * @return array{columns: int, cell: int, cells: int}|null
     */
    private function shape(string $csv): ?array
    {
        $content = trim(str_starts_with($csv, "\xEF\xBB\xBF") ? substr($csv, 3) : $csv);
        if ($content === '') {
            return null;
        }
        $delimiter = (new ReflectionMethod(DelimitedTableParser::class, 'detectDelimiter'))->invoke(new DelimitedTableParser(), $content, 'x.csv');
        self::assertIsString($delimiter);
        $stream = fopen('php://temp', 'r+');
        self::assertIsResource($stream);
        fwrite($stream, $content);
        rewind($stream);
        $shape   = ['columns' => 0, 'cell' => 0, 'cells' => 0];
        $records = 0;
        while (($record = fgetcsv($stream, null, $delimiter, '"', '')) !== false) {
            $shape['columns'] = max($shape['columns'], count($record));
            foreach ($record as $value) {
                $shape['cell'] = max($shape['cell'], strlen((string) $value));
            }
            if ($records++ > 0 && $record !== [null]) {
                $shape['cells'] += count($record);
            }
        }
        fclose($stream);

        return $shape;
    }
}
