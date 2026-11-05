<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Support;

use function strtr;

/**
 * Since 1.25 (audit FP-L2): LIKE patterns with `!` as the escape character, the same on MySQL, PostgreSQL and SQLite
 * (SQLite has no default escape). Use with `LIKE :param ESCAPE '!'`.
 *
 * @internal
 */
final class Like
{
    public const ESCAPE = '!';

    /** The text with `!`, `%` and `_` escaped, so each matches only itself. */
    public static function literal(string $text): string
    {
        return strtr($text, ['!' => '!!', '%' => '!%', '_' => '!_']);
    }
}
