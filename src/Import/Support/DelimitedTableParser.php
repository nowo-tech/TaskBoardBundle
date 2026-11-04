<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Import\Support;

use Nowo\TaskBoardBundle\Exception\ImportLimitExceededException;

use function array_map;
use function assert;
use function count;
use function fclose;
use function fgetcsv;
use function fopen;
use function fwrite;
use function is_resource;
use function mb_strtolower;
use function rewind;
use function str_replace;
use function str_starts_with;
use function strlen;
use function substr;
use function trim;

/**
 * CSV/TSV parsing helpers for vendor exports.
 */
final readonly class DelimitedTableParser
{
    /** The blanks PHP's CSV parser skips before an opening quote (isspace(); a line break ends the record first). */
    private const BLANKS = " \t\r\x0B\x0C";

    /** Since 1.22: default `import.max_cell_length` (bytes). */
    public const DEFAULT_MAX_CELL_LENGTH = 65536;

    /** Since 1.22.1: default `import.max_columns`. */
    public const DEFAULT_MAX_COLUMNS = 512;

    /** Since 1.22.1: default `import.max_cells` (cells per file: columns × rows, empty cells included). */
    public const DEFAULT_MAX_CELLS = 1_000_000;

    /**
     * @param int $maxRows the most data rows read (one more is refused, audit DL-L4)
     * @param int $maxCellLength the longest cell in bytes (a longer one is refused)
     * @param int $maxColumns since 1.22.1: the most columns of the header or of any row (audit DL-L6)
     * @param int $maxCells since 1.22.1: the most cells of the file (columns × rows, empty cells included)
     */
    public function __construct(
        private int $maxRows = 5000,
        private int $maxCellLength = self::DEFAULT_MAX_CELL_LENGTH,
        private int $maxColumns = self::DEFAULT_MAX_COLUMNS,
        private int $maxCells = self::DEFAULT_MAX_CELLS,
    ) {
    }

    /**
     * The rows of a CSV / TSV export keyed by header. Since 1.22: a UTF-8 byte order mark (ClickUp, Excel) is dropped,
     * quoted cells may span lines (multi-line descriptions), and a header that repeats (Jira writes one "Labels" or
     * "Inward issue link (Blocks)" column per value) joins its non-empty values with ";".
     *
     * Since 1.22.1 (audits DL-L6, EB) every limit is checked on the raw bytes before any record is built: a header or a
     * row with more than `maxColumns` columns, more than `maxCells` cells (columns × rows, empty cells included), more
     * than `maxRows` rows (blank lines included) and a cell longer than `maxCellLength` are refused.
     *
     * @throws ImportLimitExceededException
     *
     * @return list<array<string, string>>
     */
    public function parse(string $content, string $filename = ''): array
    {
        $content   = trim(str_starts_with($content, "\xEF\xBB\xBF") ? substr($content, 3) : $content);
        $delimiter = $this->detectDelimiter($content, $filename);
        if ($content === '') {
            return [];
        }
        // Audit EB-L1 / EB-L2 / EB-I1: the shape of the file is checked on the raw bytes, before fgetcsv() builds a
        // single record (one line of delimiters would otherwise become one array entry per delimiter).
        $this->prescan($content, $delimiter);
        $stream = fopen('php://temp', 'r+');
        assert(is_resource($stream));
        fwrite($stream, $content);
        rewind($stream);

        try {
            return $this->readRows($stream, $delimiter);
        } finally {
            fclose($stream);
        }
    }

    /**
     * One pass over the raw CSV, without building anything: counts the columns of each record (delimiters outside
     * quotes), the cells, the records (blank lines too) and the length of each cell, and throws at the first limit
     * passed. Quoted cells (which may hold delimiters and line breaks) are skipped in one step.
     *
     * Audit EB-L4: records and quotes are found exactly as fgetcsv() finds them. A record ends at "\n" only (a lone
     * "\r" is part of the cell, "\r\n" ends the record), and a quote opens a cell when only PHP's leading blanks
     * (space, "\t", "\r", "\v", "\f", except the delimiter) come before it in the cell.
     *
     * @throws ImportLimitExceededException
     */
    private function prescan(string $content, string $delimiter): void
    {
        $length    = strlen($content);
        $stops     = $delimiter . "\"\n";
        $blanks    = str_replace($delimiter, '', self::BLANKS);
        $columns   = 1;
        $records   = 0;
        $cells     = 0;
        $cellStart = 0;
        $filled    = false;
        // Up to $length included: the end of the text closes the last record (trim() left no line break after it).
        for ($i = 0; $i <= $length;) {
            $skip = strcspn($content, $stops, $i);
            // Whether the cell already has a character other than a leading blank (a quote then is a plain one).
            $filled = $filled || strspn($content, $blanks, $i, $skip) < $skip;
            $i += $skip;
            $char = $content[$i] ?? "\n";
            if ($char === '"') {
                if ($filled) {
                    // A quote inside a cell is a plain character (as for fgetcsv(): only a cell's start opens one).
                    ++$i;
                    continue;
                }
                // A quoted cell, which may hold delimiters and line breaks: its end is the next quote not doubled.
                for ($from = $i + 1;; $from = $close + 2) {
                    $close = strpos($content, '"', $from);
                    if ($close === false || ($content[$close + 1] ?? '') !== '"') {
                        break;
                    }
                }
                $i      = $close === false ? $length : $close + 1;
                $filled = true;
                if ($i - $cellStart > $this->maxCellLength) {
                    throw ImportLimitExceededException::cellLength($this->maxCellLength);
                }
                continue;
            }
            // fgetcsv() drops the "\r" of a "\r\n" that ends the record.
            $end = $char === "\n" && $i > $cellStart && $content[$i - 1] === "\r" ? $i - 1 : $i;
            if ($end - $cellStart > $this->maxCellLength) {
                throw ImportLimitExceededException::cellLength($this->maxCellLength);
            }
            if ($char === $delimiter) {
                if (++$columns > $this->maxColumns) {
                    throw ImportLimitExceededException::columns($this->maxColumns);
                }
                $cellStart = ++$i;
                $filled    = false;
                continue;
            }
            // End of a record; the header is not a row, a blank line is.
            if ($records > 0) {
                if ($records > $this->maxRows) {
                    throw ImportLimitExceededException::rows($this->maxRows);
                }
                $cells += $columns;
                if ($cells > $this->maxCells) {
                    throw ImportLimitExceededException::cells($this->maxCells);
                }
            }
            ++$records;
            $columns   = 1;
            $filled    = false;
            $cellStart = ++$i;
        }
    }

    /**
     * @param resource $stream
     *
     * @return list<array<string, string>>
     */
    private function readRows($stream, string $delimiter): array
    {
        // The limits were checked by prescan() on the raw bytes; each record is checked again (defence in depth).
        $headers = array_map(static fn (?string $header): string => trim((string) $header), $this->checked($this->readRecord($stream, $delimiter) ?? []));
        $rows    = [];
        while (($values = $this->readRecord($stream, $delimiter)) !== null) {
            if ($values === [null]) {
                continue;
            }
            $this->checked($values);

            $normalized = [];
            foreach ($headers as $index => $header) {
                $value = trim((string) ($values[$index] ?? ''));
                if (!isset($normalized[$header]) || $normalized[$header] === '') {
                    $normalized[$header] = $value;
                } elseif ($value !== '') {
                    $normalized[$header] .= ';' . $value;
                }
            }

            $rows[] = $normalized;
        }

        return $rows;
    }

    /**
     * Audit EB-L4: the record fgetcsv() built, checked again against the column and cell limits before it is kept.
     *
     * @param list<string|null> $record
     *
     * @throws ImportLimitExceededException
     *
     * @return list<string|null>
     */
    private function checked(array $record): array
    {
        if (count($record) > $this->maxColumns) {
            throw ImportLimitExceededException::columns($this->maxColumns);
        }
        foreach ($record as $value) {
            if (strlen((string) $value) > $this->maxCellLength) {
                throw ImportLimitExceededException::cellLength($this->maxCellLength);
            }
        }

        return $record;
    }

    /**
     * One CSV record (RFC 4180 quoting, no backslash escape); null at the end of the stream.
     *
     * @param resource $stream
     *
     * @return list<string|null>|null
     */
    private function readRecord($stream, string $delimiter): ?array
    {
        $record = fgetcsv($stream, null, $delimiter, '"', '');

        return $record === false ? null : $record;
    }

    private function detectDelimiter(string $content, string $filename): string
    {
        if (str_ends_with(mb_strtolower($filename), '.tsv')) {
            return "\t";
        }

        $start     = strspn($content, "\r\n");
        $firstLine = substr($content, $start, strcspn($content, "\r\n", $start));
        $comma     = substr_count($firstLine, ',');
        $tab       = substr_count($firstLine, "\t");
        $semi      = substr_count($firstLine, ';');

        if ($tab >= $comma && $tab >= $semi && $tab > 0) {
            return "\t";
        }

        if ($semi > $comma) {
            return ';';
        }

        return ',';
    }

    /**
     * Stream-friendly parser kept for large files.
     *
     * @return list<array<string, string>>
     */
    public function parseStream(mixed $stream): array
    {
        if (!is_resource($stream)) {
            return [];
        }

        $headers = fgetcsv($stream);
        if ($headers === false) {
            return [];
        }

        $headers = array_map(static fn (?string $header): string => trim((string) $header), $headers);
        if ($headers === ['']) {
            return [];
        }

        $rows = [];

        while (($values = fgetcsv($stream)) !== false) {
            $assoc = [];
            foreach ($headers as $index => $header) {
                $assoc[$header] = trim($values[$index] ?? '');
            }

            $rows[] = $assoc;
        }

        return $rows;
    }
}
