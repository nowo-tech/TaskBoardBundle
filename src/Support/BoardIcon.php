<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Support;

use function preg_match;
use function strlen;

/**
 * Since 1.30.0 (spec 211): a board's icon: one emoji, at most {@see self::MAX_BYTES} bytes. Stored apart from the name.
 * Written out by ranges, so it does not depend on the PCRE build knowing `Extended_Pictographic`.
 */
final class BoardIcon
{
    public const MAX_BYTES = 16;

    private const BASE = '[\x{00A9}\x{00AE}\x{203C}\x{2049}\x{2122}\x{2139}\x{2190}-\x{21FF}\x{2300}-\x{23FF}\x{24C2}\x{25A0}-\x{27BF}\x{2900}-\x{297F}\x{2B00}-\x{2BFF}\x{3030}\x{303D}\x{3297}\x{3299}\x{1F000}-\x{1F1E5}\x{1F200}-\x{1FAFF}]';

    private const MODIFIERS = '(?:\x{FE0F}|[\x{1F3FB}-\x{1F3FF}])*';

    /**
     * Since 2.9.0 (audit JM-I3): tag characters only in a subdivision flag, black flag + 1 to 6 tag letters or digits + cancel
     * tag (the England flag is 28 bytes, over {@see self::MAX_BYTES}); anywhere else they could carry hidden text.
     */
    private const TAG_FLAG = '\x{1F3F4}[\x{E0030}-\x{E0039}\x{E0061}-\x{E007A}]{1,6}\x{E007F}';

    /** One emoji: a ZWJ sequence, a flag or a keycap. No text, no second emoji, no control character. */
    public const PATTERN = '/^(?:' . self::TAG_FLAG . '|' . self::BASE . self::MODIFIERS . '(?:\x{200D}' . self::BASE . self::MODIFIERS . ')*|[\x{1F1E6}-\x{1F1FF}]{2}|[0-9#*]\x{FE0F}?\x{20E3})$/uD';

    public static function isValid(string $icon): bool
    {
        return strlen($icon) <= self::MAX_BYTES && preg_match(self::PATTERN, $icon) === 1;
    }
}
