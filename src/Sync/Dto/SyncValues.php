<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Sync\Dto;

use DateTimeImmutable;
use Throwable;

use function is_numeric;
use function is_scalar;
use function is_string;

/**
 * @internal since 1.23: reading the JSON the sync stores (never user input, but typed defensively)
 */
final class SyncValues
{
    public static function int(mixed $value): int
    {
        return is_numeric($value) ? (int) $value : 0;
    }

    public static function string(mixed $value): string
    {
        return is_scalar($value) ? (string) $value : '';
    }

    public static function date(mixed $value): ?DateTimeImmutable
    {
        if (!is_string($value) || $value === '') {
            return null;
        }
        try {
            return new DateTimeImmutable($value);
        } catch (Throwable) {
            return null;
        }
    }
}
