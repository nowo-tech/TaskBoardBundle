<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Import\Support;

use DateTimeImmutable;
use DateTimeZone;
use Nowo\TaskBoardBundle\Enum\TaskDependencyType;
use Nowo\TaskBoardBundle\Enum\TaskPriority;

use function date_default_timezone_get;
use function in_array;
use function intdiv;
use function is_numeric;
use function mb_strtolower;
use function preg_match;
use function preg_replace;
use function preg_split;
use function str_replace;
use function strlen;
use function strtotime;
use function trim;

/**
 * Shared field normalizers for vendor exports.
 */
final class ImportFieldMapper
{
    /**
     * Since 1.22: the formats tried in order (without the time zone, which {@see self::parseDate()} reads through
     * strtotime()); a format that overflows (month 25) is refused so the next one is tried.
     */
    private const DATE_FORMATS = [
        'Y-m-d H:i:s',
        'Y-m-d H:i',
        'Y-m-d',
        'Y/m/d',
        'd/m/Y H:i:s',
        'd/m/Y H:i',
        'd/m/Y g:i:s A',
        'd/m/Y g:i A',
        'd/m/Y',
        'm/d/Y H:i:s',
        'm/d/Y H:i',
        'm/d/Y g:i:s A',
        'm/d/Y g:i A',
        'm/d/Y',
        'd.m.Y H:i',
        'd.m.Y',
        'd-m-Y',
        'd/M/y g:i A',
        'd/M/Y g:i A',
        'd/M/y',
        'M j Y g:i:s A',
        'M j Y g:i A',
        'M j Y',
        'j M Y',
    ];

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
        $seen  = [];
        foreach ($parts as $part) {
            // Since 1.22: ClickUp writes lists as "[a, b]".
            $tag = trim($part, " \t\n\r\0\x0B[]\"'");
            if ($tag !== '' && !isset($seen[$tag])) {
                $seen[$tag] = true;
                $tags[]     = $tag;
            }
        }

        return $tags;
    }

    public function parseDueDate(string $raw): ?DateTimeImmutable
    {
        return $this->parseDate($raw);
    }

    /**
     * Since 1.22: any date of a vendor export, deterministic (a date without a time is midnight, never "now"):
     * Unix timestamps in milliseconds (ClickUp, 11+ digits) or seconds (9-10 digits), ISO 8601, day-first and
     * month-first numeric dates (day first when both read: 03/04/2026 is 3 April), Jira's "03/Nov/26 10:15 AM",
     * and the text dates of ClickUp ("Friday, March 1st 2024, 9:46:52 am +01:00").
     */
    public function parseDate(string $raw): ?DateTimeImmutable
    {
        $raw = trim($raw);
        if ($raw === '') {
            return null;
        }

        if (preg_match('/^\d{9,}$/', $raw) === 1) {
            return $this->inRange($this->fromTimestamp(strlen($raw) >= 11 ? intdiv((int) $raw, 1000) : (int) $raw));
        }

        // Week day, ordinal suffixes and commas only decorate the text dates ("Friday, March 1st 2024, 9:46 am").
        $clean = preg_replace('/^(monday|tuesday|wednesday|thursday|friday|saturday|sunday|mon|tue|wed|thu|fri|sat|sun)\b\.?,?\s*/i', '', $raw) ?? $raw;
        $clean = preg_replace('/\b(\d{1,2})(st|nd|rd|th)\b/i', '$1', $clean) ?? $clean;
        $clean = trim(preg_replace('/\s+/', ' ', str_replace(',', ' ', $clean)) ?? $clean);

        foreach (self::DATE_FORMATS as $format) {
            $date = DateTimeImmutable::createFromFormat('!' . $format, $clean);
            if ($date instanceof DateTimeImmutable && DateTimeImmutable::getLastErrors() === false) {
                return $this->inRange($date);
            }
        }

        // The last resort needs a four-digit year: relative input ("now", "next monday") is not a date of the source.
        $timestamp = preg_match('/\d{4}/', $clean) === 1 ? strtotime($clean) : false;

        return $timestamp !== false ? $this->inRange($this->fromTimestamp($timestamp)) : null;
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

    /**
     * Since 1.22: the people of an assignee cell that holds names, not e-mails ("Ada Lovelace, Alan Turing",
     * "[Ada Lovelace]"); empty when the cell names someone by e-mail.
     *
     * @return list<string>
     */
    public function parseNames(string $raw): array
    {
        if ($this->normalizeEmail($raw) !== null) {
            return [];
        }
        $names = [];
        $seen  = [];
        foreach (preg_split('/[,;|]/', $raw) ?: [] as $part) {
            $name = trim(preg_replace('/\s+/', ' ', trim($part, " \t\n\r\0\x0B[]\"'")) ?? '');
            if ($name !== '' && !isset($seen[$name])) {
                $seen[$name] = true;
                $names[]     = $name;
            }
        }

        return $names;
    }

    /**
     * Since 1.22: the task ids of a dependency cell ("86abc, 86def", "[86abc]", "#86abc"), once each.
     *
     * @return list<string>
     */
    public function parseIdList(string $raw): array
    {
        $ids  = [];
        $seen = [];
        foreach (preg_split('/[\s,;|]+/', $raw) ?: [] as $part) {
            $id = ltrim(trim($part, "[]\"'"), '#');
            if ($id !== '' && !isset($seen[$id])) {
                $seen[$id] = true;
                $ids[]     = $id;
            }
        }

        return $ids;
    }

    /**
     * Since 1.22: Gantt relations from two id cells — the tasks this one waits on (blocked by) and the ones it blocks —
     * without a relation to the row itself.
     *
     * @return list<array{0: string, 1: TaskDependencyType}>
     */
    public function mapDependencies(string $waitingOn, string $blocking, ?string $selfId = null): array
    {
        $dependencies = [];
        foreach ([[$waitingOn, TaskDependencyType::BlockedBy], [$blocking, TaskDependencyType::Blocks]] as [$cell, $type]) {
            foreach ($this->parseIdList($cell) as $id) {
                if ($id !== $selfId) {
                    $dependencies[] = [$id, $type];
                }
            }
        }

        return $dependencies;
    }

    public function stripHtml(string $raw): string
    {
        $plain = preg_replace('/<[^>]+>/', ' ', $raw) ?? $raw;

        return trim(preg_replace('/\s+/', ' ', $plain) ?? $plain);
    }

    /**
     * Since 1.22: a date a database column keeps (years 1970-2200), null otherwise.
     */
    private function inRange(DateTimeImmutable $date): ?DateTimeImmutable
    {
        $year = (int) $date->format('Y');

        return $year >= 1970 && $year <= 2200 ? $date : null;
    }

    /**
     * A Unix timestamp in the default time zone (no dependency on the current time).
     */
    private function fromTimestamp(int $timestamp): DateTimeImmutable
    {
        return (new DateTimeImmutable('@' . $timestamp))->setTimezone(new DateTimeZone(date_default_timezone_get()));
    }
}
