<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\ValueObject;

use InvalidArgumentException;
use Stringable;

use function mb_strlen;

/**
 * Since 2.15.0 (REQ-ARCH-001 rule 2, `database.user_reference: id`): a host user referenced by id. With the id mode the
 * TaskBoard rows keep the user's id in a string column instead of a Doctrine relation to `user_class`, and a row read back
 * holds this value in its user field (creator, author, member, ...). The bundle finds the host user again through
 * `user_directory` when it needs more than the id (a label, an avatar). The id is exact: never trimmed, case-folded or
 * normalised (at most 128 characters).
 */
final readonly class TaskBoardUserRef implements Stringable
{
    public const MAX_LENGTH = 128;

    public function __construct(public string $id)
    {
        if ($id === '' || mb_strlen($id) > self::MAX_LENGTH) {
            throw new InvalidArgumentException('A TaskBoard user reference is a non-empty id of at most 128 characters.');
        }
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
