<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Sync\Status;

use Normalizer;
use Nowo\TaskBoardBundle\Entity\BoardColumn;
use Nowo\TaskBoardBundle\Entity\TaskBoard;
use Nowo\TaskBoardBundle\Sync\Provider\RemoteStatusType;

use function class_exists;
use function count;
use function is_array;
use function is_string;
use function mb_strtolower;
use function mb_substr;
use function preg_replace;
use function trim;

/**
 * Since 1.25: the connection's status → column map (Beacon's connection dialog). An entry's `column` is a column id
 * of the board, {@see self::CREATE} (a column named like the status, created when the link is confirmed) or
 * {@see self::SKIP} (tasks in that status are not imported). Statuses are compared normalised: case, accents and
 * spaces ignored.
 */
final class StatusColumns
{
    public const CREATE = '__create';

    public const SKIP = '__skip';

    /** Case, accents and white space removed. */
    public static function normalize(?string $status): string
    {
        $status = trim((string) $status);
        if (class_exists(Normalizer::class)) {
            $status = (string) preg_replace('/\p{Mn}+/u', '', (string) Normalizer::normalize($status, Normalizer::FORM_D));
        }

        return mb_strtolower((string) preg_replace('/\s+/u', '', $status));
    }

    /**
     * The map's target of a status: a column id, {@see self::SKIP}, or null (not in the map, or `__create` not
     * resolved yet).
     *
     * @param list<array{status: string, column: string, type?: string}> $map
     */
    public static function target(array $map, ?string $status): ?string
    {
        $key = self::normalize($status);
        if ($key === '') {
            return null;
        }
        foreach ($map as $entry) {
            if (self::normalize($entry['status']) === $key) {
                return $entry['column'] === self::CREATE ? null : $entry['column'];
            }
        }

        return null;
    }

    /**
     * Whether the map sends the status to an existing column of the board, or skips it (then no column is created).
     *
     * @param list<array{status: string, column: string, type?: string}> $map
     */
    public static function mapped(array $map, ?string $status, TaskBoard $board): bool
    {
        $target = self::target($map, $status);
        if ($target === self::SKIP) {
            return true;
        }
        foreach ($board->getColumns() as $column) {
            if ($column->getId() === $target) {
                return true;
            }
        }

        return false;
    }

    /**
     * The column done work goes to (§5.5): the column the map gives the first `closed` (else `done`) status, a
     * `__create` one found by name; null when the map has no typed done status (the caller falls back to the last
     * column, the 1.23 rule).
     *
     * @param list<array{status: string, column: string, type?: string}> $map
     */
    public static function doneColumn(array $map, TaskBoard $board): ?BoardColumn
    {
        foreach ([RemoteStatusType::Closed, RemoteStatusType::Done] as $type) {
            foreach ($map as $entry) {
                if (($entry['type'] ?? null) !== $type->value || $entry['column'] === self::SKIP) {
                    continue;
                }
                foreach ($board->getColumns() as $column) {
                    if ($column->getId() === $entry['column'] || ($entry['column'] === self::CREATE && self::normalize($column->getName()) === self::normalize($entry['status']))) {
                        return $column;
                    }
                }
            }
        }

        return null;
    }

    /**
     * Since 2.5.0: whether the map asks for a column to be created.
     *
     * @param list<array{status: string, column: string, type?: string}> $map
     */
    public static function hasCreate(array $map): bool
    {
        foreach ($map as $entry) {
            if ($entry['column'] === self::CREATE) {
                return true;
            }
        }

        return false;
    }

    /**
     * Validates a posted map against the board: known columns, `__create` or `__skip`; empty statuses dropped, each
     * status once (the first wins). At most 200 entries.
     *
     * @param iterable<mixed> $entries
     *
     * @return list<array{status: string, column: string, type?: string}>
     */
    public static function clean(iterable $entries, TaskBoard $board): array
    {
        $columns = [];
        foreach ($board->getColumns() as $column) {
            $columns[$column->getId()] = true;
        }
        $map  = [];
        $seen = [];
        foreach ($entries as $entry) {
            if (!is_array($entry) || !is_string($entry['status'] ?? null) || !is_string($entry['column'] ?? null)) {
                continue;
            }
            $status = mb_substr(trim($entry['status']), 0, 255);
            $column = $entry['column'];
            $key    = self::normalize($status);
            if ($key === '' || isset($seen[$key]) || ($column !== self::CREATE && $column !== self::SKIP && !isset($columns[$column]))) {
                continue;
            }
            $seen[$key] = true;
            $type       = is_string($entry['type'] ?? null) ? RemoteStatusType::tryFrom($entry['type']) : null;
            // Since 1.25: the status type (from the pre-scan) says which column receives done checklist items.
            $map[] = $type instanceof RemoteStatusType ? ['status' => $status, 'column' => $column, 'type' => $type->value] : ['status' => $status, 'column' => $column];
            if (count($map) >= 200) {
                break;
            }
        }

        return $map;
    }
}
