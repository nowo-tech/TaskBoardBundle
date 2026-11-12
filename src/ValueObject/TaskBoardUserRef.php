<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\ValueObject;

use InvalidArgumentException;
use Stringable;

use function mb_strlen;
use function preg_match;

/**
 * Since 2.15.0 (REQ-ARCH-001 rule 2, `database.user_reference: id`): a host user referenced by id. With the id mode the
 * TaskBoard rows keep the user's id in a string column instead of a Doctrine relation to `user_class`, and a row read back
 * holds this value in its user field (creator, author, member, ...). The bundle finds the host user again through
 * `user_directory` when it needs more than the id (a label, an avatar). The id is exact: never trimmed, case-folded or
 * normalised; one the bundle cannot keep exactly is refused ({@see self::isValid()}).
 */
final readonly class TaskBoardUserRef implements Stringable
{
    public const MAX_LENGTH = 128;

    public function __construct(public string $id)
    {
        if (!self::isValid($id)) {
            throw new InvalidArgumentException('A TaskBoard user reference is a valid UTF-8 id of 1 to 128 characters, without whitespace at its edges and without control or format characters.');
        }
    }

    /**
     * Since 2.15.0 (audit OC-M1): whether an id can be kept exactly. Refused: empty, more than 128 characters, invalid
     * UTF-8, whitespace (Unicode, NBSP included) at either edge, and control or format characters anywhere (ZWSP,
     * BOM, bidi marks...). A PAD SPACE collation would otherwise take `"u "` for `"u"`, and an invisible character
     * would make two different ids look alike.
     */
    public static function isValid(string $id): bool
    {
        if ($id === '' || preg_match('//u', $id) !== 1 || mb_strlen($id) > self::MAX_LENGTH) {
            return false;
        }

        return preg_match('/^[\s\p{Z}]|[\s\p{Z}]$|\p{C}/u', $id) !== 1;
    }

    /** The id, as the user's own `getId()` (the bundle reads ids through `getId()`). */
    public function getId(): string
    {
        return $this->id;
    }

    public function __toString(): string
    {
        return $this->id;
    }
}
