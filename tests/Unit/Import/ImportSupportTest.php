<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Tests\Unit\Import;

use Nowo\TaskBoardBundle\Enum\TaskPriority;
use Nowo\TaskBoardBundle\Import\Support\DelimitedTableParser;
use Nowo\TaskBoardBundle\Import\Support\ImportFieldMapper;
use PHPUnit\Framework\TestCase;

final class ImportSupportTest extends TestCase
{
    public function testParserDetectsDelimiters(): void
    {
        $parser = new DelimitedTableParser();

        self::assertSame([['a' => '1', 'b' => '2']], $parser->parse("a\tb\n1\t2\n", 'export.TSV'));
        self::assertSame([['a' => '1', 'b' => '2']], $parser->parse("\n\na\tb\n1\t2\n", 'export.txt'));
        self::assertSame([['a' => '1', 'b' => '2']], $parser->parse("a;b\n1;2\n", 'export.csv'));
        self::assertSame([['a' => '1', 'b' => '']], $parser->parse("a,b\n\n1\n", 'export.csv'));
        self::assertSame([], $parser->parse('', 'export.csv'));
    }

    public function testParseStream(): void
    {
        $parser = new DelimitedTableParser();

        self::assertSame([], $parser->parseStream('not a stream'));

        $empty = fopen('php://memory', 'r+');
        self::assertIsResource($empty);
        self::assertSame([], $parser->parseStream($empty));

        $blankHeader = fopen('php://memory', 'r+');
        self::assertIsResource($blankHeader);
        fwrite($blankHeader, "\n1,2\n");
        rewind($blankHeader);
        self::assertSame([], $parser->parseStream($blankHeader));

        $stream = fopen('php://memory', 'r+');
        self::assertIsResource($stream);
        fwrite($stream, "name , status\nShip, Done \nOnly\n");
        rewind($stream);
        self::assertSame([
            ['name' => 'Ship', 'status' => 'Done'],
            ['name' => 'Only', 'status' => ''],
        ], $parser->parseStream($stream));
    }

    public function testPriorityMapping(): void
    {
        $mapper = new ImportFieldMapper();

        self::assertSame(TaskPriority::Normal, $mapper->mapPriority(' '));
        self::assertSame(TaskPriority::Urgent, $mapper->mapPriority('P1'));
        self::assertSame(TaskPriority::High, $mapper->mapPriority('2'));
        self::assertSame(TaskPriority::Low, $mapper->mapPriority('Lowest'));
        self::assertSame(TaskPriority::Normal, $mapper->mapPriority('medium'));
    }

    public function testDueDateParsing(): void
    {
        $mapper = new ImportFieldMapper();

        self::assertNull($mapper->parseDueDate(''));
        self::assertSame('2026-08-01', $mapper->parseDueDate('1785542400000')?->format('Y-m-d'));
        self::assertSame('2026-08-01', $mapper->parseDueDate('2026-08-01')?->format('Y-m-d'));
        self::assertSame('2026-08-01', $mapper->parseDueDate('2026-08-01T12:00:00+00:00')?->format('Y-m-d'));
        self::assertNull($mapper->parseDueDate('definitely not a date'));
    }

    public function testEstimateParsing(): void
    {
        $mapper = new ImportFieldMapper();

        self::assertNull($mapper->parseEstimatedMinutes(''));
        self::assertSame(45, $mapper->parseEstimatedMinutes('45'));
        self::assertSame(0, $mapper->parseEstimatedMinutes('-5'));
        self::assertSame(60, $mapper->parseEstimatedMinutes('3600000'));
        self::assertSame(150, $mapper->parseEstimatedMinutes('2h 30m'));
        self::assertSame(120, $mapper->parseEstimatedMinutes('2h'));
        self::assertSame(15, $mapper->parseEstimatedMinutes('15m'));
        self::assertSame(90, $mapper->parseEstimatedMinutes('1:30'));
        self::assertNull($mapper->parseEstimatedMinutes('soon'));
    }

    public function testEmailNormalisation(): void
    {
        $mapper = new ImportFieldMapper();

        self::assertNull($mapper->normalizeEmail(''));
        self::assertSame('jane@example.com', $mapper->normalizeEmail('Jane Doe <Jane@Example.com>'));
        self::assertSame('a@example.com', $mapper->normalizeEmail('A@example.com'));
        self::assertSame('b@example.com', $mapper->normalizeEmail('nobody, b@example.com; c@example.com'));
        self::assertNull($mapper->normalizeEmail('nobody; somebody'));
    }

    public function testTagsAndPick(): void
    {
        $mapper = new ImportFieldMapper();

        self::assertSame([], $mapper->parseTags('  '));
        self::assertSame(['a', 'b'], $mapper->parseTags('a, b;a|'));
        self::assertSame('x', $mapper->pick([' Name ' => ' x ', 'Other' => ''], 'other', 'NAME'));
        self::assertSame('', $mapper->pick(['Other' => ''], 'other'));
        self::assertSame('a b', $mapper->stripHtml('<p>a</p>  <br>b'));
    }
}
