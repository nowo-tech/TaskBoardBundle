<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Support;

use function mb_strlen;
use function mb_substr;

/**
 * Since 1.16: strings cut to their column length at the service / entity level, so a long title or label coming from
 * code (imports, host integrations) never reaches the database as "Data too long".
 */
final class Text
{
    public static function cut(string $text, int $max): string
    {
        return mb_strlen($text) > $max ? mb_substr($text, 0, $max) : $text;
    }

    public static function cutNullable(?string $text, int $max): ?string
    {
        return $text === null ? null : self::cut($text, $max);
    }
}
