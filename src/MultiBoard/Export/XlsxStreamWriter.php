<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\MultiBoard\Export;

use DeflateContext;
use LogicException;
use Nowo\ControllerKitBundle\Http\CsvSafeWriter;

use function chr;
use function count;
use function deflate_add;
use function deflate_init;
use function fwrite;
use function hash_final;
use function hash_init;
use function hash_update;
use function htmlspecialchars;
use function intdiv;
use function is_float;
use function is_int;
use function pack;
use function preg_replace;
use function strlen;

use const ENT_QUOTES;
use const ENT_SUBSTITUTE;
use const ENT_XML1;
use const ZLIB_ENCODING_RAW;
use const ZLIB_FINISH;
use const ZLIB_NO_FLUSH;

/**
 * Since 2.13.0: a one-sheet XLSX written to a stream row by row (Office Open XML in a ZIP whose entries carry data
 * descriptors, deflated with ext-zlib), so an export never holds the workbook in memory and needs no spreadsheet
 * library. Text cells are inline strings: a spreadsheet never evaluates them, and they also go through
 * {@see CsvSafeWriter::cell()} so a cell that starts with `=`, `+`, `-`, `@`, a tab or a carriage return reads as text
 * even after the file is saved as CSV (formula injection). Numbers stay numbers.
 *
 * One writer per file: a new instance per export, nothing kept between exports (FrankenPHP worker mode).
 *
 * @internal
 */
final class XlsxStreamWriter
{
    /** ZIP "version needed to extract" 2.0 (deflate, data descriptor). */
    private const VERSION = 20;

    /** General purpose flags: sizes in a data descriptor (bit 3), UTF-8 names (bit 11). */
    private const FLAGS = 0x0808;

    /** Fixed DOS time and date of every entry (1980-01-01 00:00). */
    private const DOS_TIME = 0;

    private const DOS_DATE = 0x21;

    /** @var list<array{name: string, crc: int, compressed: int, size: int, offset: int}> */
    private array $entries = [];

    private int $offset = 0;

    /** The entry being written: its name, offset, hash, deflate context and sizes. */
    private ?string $current;

    private int $currentOffset;

    private mixed $hash;

    private ?DeflateContext $deflate;

    private int $size;

    private int $compressed;

    private int $row = 0;

    private bool $closed = false;

    /**
     * @param resource $out writable stream (`php://output` for a response)
     * @param list<string> $header the first row (bold is not used: plain text)
     */
    public function __construct(private $out, private readonly array $header, private readonly string $sheetName = 'Tasks')
    {
        $this->entry('[Content_Types].xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n"
            . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
            . '<Default Extension="xml" ContentType="application/xml"/>'
            . '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
            . '<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
            . '<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>'
            . '</Types>');
        $this->entry('_rels/.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n"
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
            . '</Relationships>');
        $this->entry('xl/workbook.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n"
            . '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
            . '<sheets><sheet name="' . self::xml($this->sheetName) . '" sheetId="1" r:id="rId1"/></sheets>'
            . '</workbook>');
        $this->entry('xl/_rels/workbook.xml.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n"
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>'
            . '<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>'
            . '</Relationships>');
        $this->entry('xl/styles.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n"
            . '<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            . '<fonts count="1"><font><sz val="11"/><name val="Calibri"/></font></fonts>'
            . '<fills count="2"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill></fills>'
            . '<borders count="1"><border><left/><right/><top/><bottom/><diagonal/></border></borders>'
            . '<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
            . '<cellXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/></cellXfs>'
            . '</styleSheet>');

        $this->open('xl/worksheets/sheet1.xml');
        $this->write('<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n"
            . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData>');
        $this->addRow($this->header);
    }

    /**
     * One row: `int` / `float` as numbers, `null` as an empty cell, anything else as guarded text.
     *
     * @param list<float|int|string|null> $cells
     */
    public function addRow(array $cells): void
    {
        if ($this->closed) {
            throw new LogicException('The XLSX file is already closed.');
        }
        // @igor-ignore - One writer per file, created inside the export callback; never a service.
        ++$this->row;
        $xml = '<row r="' . $this->row . '">';
        foreach ($cells as $index => $value) {
            if ($value === null) {
                continue;
            }
            $ref = self::column($index) . $this->row;
            $xml .= is_int($value) || is_float($value)
                ? '<c r="' . $ref . '"><v>' . $value . '</v></c>'
                : '<c r="' . $ref . '" t="inlineStr"><is><t xml:space="preserve">' . self::xml(CsvSafeWriter::cell($value)) . '</t></is></c>';
        }
        $this->write($xml . '</row>');
    }

    /** Ends the sheet and writes the ZIP directory. */
    public function close(): void
    {
        if ($this->closed) {
            return;
        }
        $this->write('</sheetData></worksheet>');
        $this->finish();
        // @igor-ignore - One writer per file, created inside the export callback; never a service.
        $this->closed = true;

        $directory = '';
        foreach ($this->entries as $entry) {
            $directory .= pack('VvvvvvvVVVvvvvvVV', 0x02014B50, self::VERSION, self::VERSION, self::FLAGS, 8, self::DOS_TIME, self::DOS_DATE, $entry['crc'], $entry['compressed'], $entry['size'], strlen($entry['name']), 0, 0, 0, 0, 0, $entry['offset']) . $entry['name'];
        }
        $this->raw($directory);
        $count = count($this->entries);
        fwrite($this->out, pack('VvvvvVVv', 0x06054B50, 0, 0, $count, $count, strlen($directory), $this->offset - strlen($directory), 0));
    }

    /** `A`…`Z`, `AA`… for a 0-based column. */
    public static function column(int $index): string
    {
        $name = '';
        for (++$index; $index > 0; $index = intdiv($index - 1, 26)) {
            $name = chr(65 + ($index - 1) % 26) . $name;
        }

        return $name;
    }

    /** XML text: escaped, without the control characters XML 1.0 forbids. */
    public static function xml(string $text): string
    {
        return htmlspecialchars((string) preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x{FFFE}\x{FFFF}]/u', '', $text), ENT_XML1 | ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    private function entry(string $name, string $content): void
    {
        $this->open($name);
        $this->write($content);
        $this->finish();
    }

    private function open(string $name): void
    {
        // @igor-ignore - One writer per file, created inside the export callback; never a service.
        $this->current = $name;
        // @igor-ignore - One writer per file, created inside the export callback; never a service.
        $this->currentOffset = $this->offset;
        // @igor-ignore - One writer per file, created inside the export callback; never a service.
        $this->hash = hash_init('crc32b');
        $deflate    = deflate_init(ZLIB_ENCODING_RAW, ['level' => 6]);
        // @igor-ignore - One writer per file, created inside the export callback; never a service.
        $this->deflate = $deflate instanceof DeflateContext ? $deflate : null;
        // @igor-ignore - One writer per file, created inside the export callback; never a service.
        $this->size = 0;
        // @igor-ignore - One writer per file, created inside the export callback; never a service.
        $this->compressed = 0;
        $this->raw(pack('VvvvvvVVVvv', 0x04034B50, self::VERSION, self::FLAGS, 8, self::DOS_TIME, self::DOS_DATE, 0, 0, 0, strlen($name), 0) . $name);
    }

    private function write(string $data): void
    {
        hash_update($this->hash, $data);
        // @igor-ignore - One writer per file, created inside the export callback; never a service.
        $this->size += strlen($data);
        $this->compress($data, ZLIB_NO_FLUSH);
    }

    private function finish(): void
    {
        $this->compress('', ZLIB_FINISH);
        $crc = (int) hexdec(hash_final($this->hash));
        $this->raw(pack('VVVV', 0x08074B50, $crc, $this->compressed, $this->size));
        // @igor-ignore - One writer per file, created inside the export callback; never a service.
        $this->entries[] = ['name' => (string) $this->current, 'crc' => $crc, 'compressed' => $this->compressed, 'size' => $this->size, 'offset' => $this->currentOffset];
        // @igor-ignore - One writer per file, created inside the export callback; never a service.
        $this->current = null;
        // @igor-ignore - One writer per file, created inside the export callback; never a service.
        $this->deflate = null;
        // @igor-ignore - One writer per file, created inside the export callback; never a service.
        $this->hash = null;
    }

    private function compress(string $data, int $mode): void
    {
        if (!$this->deflate instanceof DeflateContext) {
            throw new LogicException('The XLSX export needs ext-zlib (deflate_init).'); // @codeCoverageIgnore
        }
        $chunk = deflate_add($this->deflate, $data, $mode);
        if ($chunk === false) {
            throw new LogicException('The XLSX export could not compress its data.'); // @codeCoverageIgnore
        }
        if ($chunk !== '') {
            // @igor-ignore - One writer per file, created inside the export callback; never a service.
            $this->compressed += strlen($chunk);
            $this->raw($chunk);
        }
    }

    private function raw(string $bytes): void
    {
        fwrite($this->out, $bytes);
        // @igor-ignore - One writer per file, created inside the export's response callback; never a service.
        $this->offset += strlen($bytes);
    }
}
