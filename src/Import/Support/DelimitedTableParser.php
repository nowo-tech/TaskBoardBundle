<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Import\Support;

use function array_map;
use function assert;
use function fclose;
use function fgetcsv;
use function fopen;
use function fwrite;
use function is_resource;
use function mb_strtolower;
use function rewind;
use function str_starts_with;
use function substr;
use function trim;

/**
 * CSV/TSV parsing helpers for vendor exports.
 */
final class DelimitedTableParser
{
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
