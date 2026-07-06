<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Import\Support;

use DateTimeImmutable;
use Nowo\TaskBoardBundle\Enum\TaskPriority;

use function in_array;
use function is_numeric;
use function mb_strtolower;
use function preg_match;
use function preg_replace;
use function preg_split;
use function trim;

/**
 * Shared field normalizers for vendor exports.
 */
final class ImportFieldMapper
{
    /**
     * @param array<string, string> $row
     */
    public function pick(array $row, string ...$candidates): string
    {
        $normalized = [];
        foreach ($row as $key => $value) {
            $normalized[mb_strtolower(trim($key))] = trim($value);
        }

        foreach ($candidates as $candidate) {
            $key = mb_strtolower(trim($candidate));
            if (($normalized[$key] ?? '') !== '') {
                return $normalized[$key];
            }
        }

        return '';
    }

    public function mapPriority(string $raw): TaskPriority
    {
        $value = mb_strtolower(trim($raw));
        if ($value === '') {
            return TaskPriority::Normal;
        }

        if (in_array($value, ['urgent', '1', 'critical', 'p1'], true)) {
            return TaskPriority::Urgent;
        }

        if (in_array($value, ['high', '2', 'p2'], true)) {
            return TaskPriority::High;
        }

        if (in_array($value, ['low', '4', 'p4', 'lowest'], true)) {
            return TaskPriority::Low;
        }

        return TaskPriority::Normal;
    }

    /**
     * @return list<string>
     */
    public function parseTags(string $raw): array
    {
        if (trim($raw) === '') {
            return [];
        }

        $parts = preg_split('/[,|;]/', $raw) ?: [];
        $tags  = [];
        foreach ($parts as $part) {
            $tag = trim($part);
            if ($tag !== '' && !in_array($tag, $tags, true)) {
                $tags[] = $tag;
            }
        }

        return $tags;
    }

    public function parseDueDate(string $raw): ?DateTimeImmutable
    {
        $raw = trim($raw);
        if ($raw === '') {
            return null;
        }

        if (is_numeric($raw) && (int) $raw > 1_000_000_000) {
            return (new DateTimeImmutable())->setTimestamp((int) floor((int) $raw / 1000));
        }

        $formats = [
            'Y-m-d H:i:s',
            'Y-m-d H:i',
            'Y-m-d',
            'd/m/Y H:i',
            'd/m/Y',
            'm/d/Y H:i',
            'm/d/Y',
            'M j, Y g:i A',
            'M j, Y',
        ];

        foreach ($formats as $format) {
            $date = DateTimeImmutable::createFromFormat($format, $raw);
            if ($date instanceof DateTimeImmutable) {
                return $date;
            }
        }

        $timestamp = strtotime($raw);

        return $timestamp !== false ? (new DateTimeImmutable())->setTimestamp($timestamp) : null;
    }

    public function parseEstimatedMinutes(string $raw): ?int
    {
        $raw = trim($raw);
        if ($raw === '') {
            return null;
        }

        if (is_numeric($raw)) {
            $value = (int) $raw;
            if ($value > 1_000_000) {
                return (int) round($value / 60_000);
            }

            return max(0, $value);
        }

        if (preg_match('/^(?<hours>\d+)\s*h(?:\s*(?<minutes>\d+)\s*m)?$/i', $raw, $matches) === 1) {
            $hours   = (int) $matches['hours'];
            $minutes = (int) ($matches['minutes'] ?? 0);

            return ($hours * 60) + $minutes;
        }

        if (preg_match('/^(?<minutes>\d+)\s*m$/i', $raw, $matches) === 1) {
            return (int) $matches['minutes'];
        }

        if (preg_match('/^(?<hours>\d+):(?<minutes>\d+)$/', $raw, $matches) === 1) {
            return ((int) $matches['hours'] * 60) + (int) $matches['minutes'];
        }

        return null;
    }

    public function normalizeEmail(string $raw): ?string
    {
        $raw = trim(mb_strtolower($raw));
        if ($raw === '') {
            return null;
        }

        if (preg_match('/<([^>]+)>/', $raw, $matches) === 1) {
            return trim($matches[1]);
        }

        if (preg_match('/^[^\s@]+@[^\s@]+\.[^\s@]+$/', $raw) === 1) {
            return $raw;
        }

        $parts = preg_split('/[,;]/', $raw) ?: [];
        foreach ($parts as $part) {
            $candidate = trim($part);
            if (preg_match('/^[^\s@]+@[^\s@]+\.[^\s@]+$/', $candidate) === 1) {
                return $candidate;
            }
        }

        return null;
    }

    public function stripHtml(string $raw): string
    {
        $plain = preg_replace('/<[^>]+>/', ' ', $raw) ?? $raw;

        return trim(preg_replace('/\s+/', ' ', $plain) ?? $plain);
    }
}
