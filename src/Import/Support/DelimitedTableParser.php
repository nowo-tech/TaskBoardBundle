<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Import\Support;

use function array_map;
use function count;
use function fgetcsv;
use function is_resource;
use function mb_strtolower;
use function preg_split;
use function str_getcsv;
use function trim;

/**
 * CSV/TSV parsing helpers for vendor exports.
 */
final class DelimitedTableParser
{
    /**
     * @return list<array<string, string>>
     */
    public function parse(string $content, string $filename = ''): array
    {
        $delimiter = $this->detectDelimiter($content, $filename);
        $lines     = preg_split("/\r\n|\r|\n/", trim($content)) ?: [];
        if ($lines === []) {
            return [];
        }

        $headers = $this->parseLine($lines[0], $delimiter);
        if ($headers === []) {
            return [];
        }

        $rows = [];
        for ($i = 1, $max = count($lines); $i < $max; ++$i) {
            $line = trim($lines[$i]);
            if ($line === '') {
                continue;
            }

            $values = $this->parseLine($line, $delimiter);
            if ($values === []) {
                continue;
            }

            $normalized = [];
            foreach ($headers as $index => $header) {
                $normalized[trim($header)] = trim((string) ($values[$index] ?? ''));
            }

            $rows[] = $normalized;
        }

        return $rows;
    }

    /**
     * @return list<string>
     */
    private function parseLine(string $line, string $delimiter): array
    {
        /** @var list<string|null> $fields */
        $fields = str_getcsv($line, $delimiter);

        return array_map(static fn (?string $value): string => trim((string) $value), $fields);
    }

    private function detectDelimiter(string $content, string $filename): string
    {
        if (str_ends_with(mb_strtolower($filename), '.tsv')) {
            return "\t";
        }

        $firstLine = strtok($content, "\r\n") ?: '';
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
