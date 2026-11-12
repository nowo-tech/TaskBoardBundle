<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Tests\Unit\MultiBoard;

use LogicException;
use Nowo\TaskBoardBundle\MultiBoard\Export\XlsxStreamWriter;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use ZipArchive;

use function fopen;
use function rewind;
use function stream_get_contents;

/**
 * Since 2.13.0: the streaming XLSX writer of the multi-board export.
 */
#[CoversClass(XlsxStreamWriter::class)]
final class XlsxStreamWriterTest extends TestCase
{
    public function testColumnNames(): void
    {
        self::assertSame(['A', 'Z', 'AA', 'AZ', 'ZZ', 'AAA'], [XlsxStreamWriter::column(0), XlsxStreamWriter::column(25), XlsxStreamWriter::column(26), XlsxStreamWriter::column(51), XlsxStreamWriter::column(701), XlsxStreamWriter::column(702)]);
    }

    public function testXmlText(): void
    {
        self::assertSame('&lt;a b=&quot;c&quot;&gt;&amp;&apos;d', XlsxStreamWriter::xml("<a b=\"c\">&'\x00d\x1F"));
        self::assertSame("tab\tline\nok", XlsxStreamWriter::xml("tab\tline\nok"));
    }

    public function testAWorkbookOfManyRows(): void
    {
        $out = fopen('php://memory', 'w+');
        self::assertNotFalse($out);
        $writer = new XlsxStreamWriter($out, ['Name', 'Count'], 'Sheet & <1>');
        for ($i = 0; $i < 2000; ++$i) {
            $writer->addRow(['Row ' . $i, $i, null, 1.5]);
        }
        $writer->close();
        $writer->close();
        rewind($out);
        $file = (string) tempnam(sys_get_temp_dir(), 'tb-xlsx');
        file_put_contents($file, (string) stream_get_contents($out));

        $zip = new ZipArchive();
        self::assertTrue($zip->open($file, ZipArchive::CHECKCONS));
        $sheet = (string) $zip->getFromName('xl/worksheets/sheet1.xml');
        self::assertStringContainsString('<row r="2001"><c r="A2001" t="inlineStr"><is><t xml:space="preserve">Row 1999</t></is></c><c r="B2001"><v>1999</v></c><c r="D2001"><v>1.5</v></c></row>', $sheet);
        self::assertStringContainsString('name="Sheet &amp; &lt;1&gt;"', (string) $zip->getFromName('xl/workbook.xml'));
        $zip->close();
        unlink($file);

        $this->expectException(LogicException::class);
        $writer->addRow(['late']);
    }
}
