<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Support;

use function in_array;
use function preg_match;
use function strlen;
use function trim;

/**
 * Since 2.9.0: what a column icon may be. Either an icon id, `<set>:<name>` such as `tabler:bug` (since 1.11; drawn through UiKit
 * `components/_icon`), or one emoji as {@see BoardIcon} accepts it (at most {@see BoardIcon::MAX_BYTES} bytes, printed as text).
 * Nothing else: no free text, no markup. Forms, the API and the config check {@see self::isValid()} / {@see self::isStorable()}; the
 * services store only what {@see self::normalize()} keeps.
 */
final class ColumnIcon
{
    /** An icon id: `<set>:<name>` in lowercase letters, digits and dashes. */
    public const ID_PATTERN = '/^[a-z0-9-]+:[a-z0-9-]+$/D';

    /**
     * What the templates draw as an icon id rather than as text. Also a bare name (`bug`): `board.default_columns[].icon` was not
     * validated before 2.9.0, so a host may have stored one; the webfont sets draw it as before.
     */
    public const RENDER_ID_PATTERN = '/^[a-z0-9-]+(?::[a-z0-9-]+)?$/D';

    /** The `task_board_columns.icon` column. */
    public const MAX_LENGTH = 64;

    public static function isEmoji(string $icon): bool
    {
        return BoardIcon::isValid($icon);
    }

    public static function isId(string $icon): bool
    {
        return strlen($icon) <= self::MAX_LENGTH && preg_match(self::ID_PATTERN, $icon) === 1;
    }

    /**
     * One emoji, or an icon id; with `$knownIds`, an id must be one of them (the `board.column_icons` and
     * `board.default_columns` icons): a write cannot name an icon the host never offered.
     *
     * @param list<string>|null $knownIds
     */
    public static function isValid(string $icon, ?array $knownIds = null): bool
    {
        if (self::isEmoji($icon)) {
            return true;
        }

        return self::isId($icon) && ($knownIds === null || in_array($icon, $knownIds, true));
    }

    /**
     * What may be stored at all: one emoji, or an id in the shape the templates draw (bare names included, for values stored before
     * 2.9.0). The last guard of every write path, behind the stricter form, API and config checks.
     */
    public static function isStorable(string $icon): bool
    {
        return self::isEmoji($icon) || (strlen($icon) <= self::MAX_LENGTH && preg_match(self::RENDER_ID_PATTERN, $icon) === 1);
    }

    /**
     * The value a write stores: null for an empty value, the trimmed value when storable, null otherwise: an invalid icon is never
     * stored.
     */
    public static function normalize(?string $icon): ?string
    {
        if ($icon === null) {
            return null;
        }
        $icon = trim($icon);

        return $icon !== '' && self::isStorable($icon) ? $icon : null;
    }
}
