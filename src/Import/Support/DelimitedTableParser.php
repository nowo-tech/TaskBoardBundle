<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Import\Support;

use InvalidArgumentException;

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
use function sprintf;
use function str_starts_with;
use function strlen;
use function substr;
use function trim;

/**
 * CSV/TSV parsing helpers for vendor exports.
 */
final readonly class DelimitedTableParser
{
    /** Since 1.22: default `import.max_cell_length` (bytes). */
    public const DEFAULT_MAX_CELL_LENGTH = 65536;

    /**
     * @param int $maxRows the most data rows read (one more is refused, audit DL-L4)
     * @param int $maxCellLength the longest cell in bytes (a longer one is refused)
     */
    public function __construct(
        private int $maxRows = 5000,
        private int $maxCellLength = self::DEFAULT_MAX_CELL_LENGTH,
    ) {
    }

    /**
     * The rows of a CSV / TSV export keyed by header. Since 1.22: a UTF-8 byte order mark (ClickUp, Excel) is dropped,
     * quoted cells may span lines (multi-line descriptions), and a header that repeats (Jira writes one "Labels" or
     * "Inward issue link (Blocks)" column per value) joins its non-empty values with ";".
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
        $stream = fopen('php://temp', 'r+');
        assert(is_resource($stream));
        fwrite($stream, $content);
        rewind($stream);

        $headers = array_map(static fn (?string $header): string => trim((string) $header), $this->readRecord($stream, $delimiter) ?? []);
        $rows    = [];
        while (($values = $this->readRecord($stream, $delimiter)) !== null) {
            if ($values === [null]) {
                continue;
            }
            if (count($rows) >= $this->maxRows) {
                fclose($stream);

                throw new InvalidArgumentException(sprintf('The file has more than %d rows; an import takes at most %d.', $this->maxRows, $this->maxRows));
            }
            foreach ($values as $value) {
                if (strlen((string) $value) > $this->maxCellLength) {
                    fclose($stream);

                    throw new InvalidArgumentException(sprintf('A cell of the file is longer than %d bytes.', $this->maxCellLength));
                }
            }

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
        fclose($stream);

        return $rows;
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
