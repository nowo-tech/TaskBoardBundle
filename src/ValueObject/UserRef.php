<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\ValueObject;

use InvalidArgumentException;
use Nowo\TaskBoardBundle\Port\TaskBoardAccessInterface;
use Nowo\TaskBoardBundle\Port\TaskCommandsInterface;
use Nowo\TaskBoardBundle\Support\ActorId;
use Nowo\TaskBoardBundle\Support\ExactKey;
use Stringable;

/**
 * Since 2.29.0 (DDD-host checklist B1/B8, REQ-ARCH-001 rule 3): the user a host acts as, or asks about, in the id-based
 * ports ({@see TaskCommandsInterface}, {@see TaskBoardAccessInterface}): the user's id and nothing else.
 *
 * It is only a pointer: the bundle looks the id up again (`user_directory` with `database.user_reference: id`, the
 * `user_class` entity otherwise; the answer must have exactly this id) and judges that user, with that user's own roles.
 * An id that names nobody is refused like a denied call, so an id never confirms that a user exists.
 *
 * The id is exact: never trimmed, case-folded or normalised; one the bundle cannot keep exactly (empty, over 128
 * characters, whitespace at an edge, control or format characters) is refused ({@see self::isValid()}).
 *
 * Built per call and never stored by the bundle (FrankenPHP worker mode, kernel reset false).
 */
final readonly class UserRef implements Stringable
{
    public const MAX_LENGTH = TaskBoardUserRef::MAX_LENGTH;

    /**
     * @throws InvalidArgumentException when the id cannot be matched exactly
     */
    public function __construct(public string $id)
    {
        if (!self::isValid($id)) {
            throw new InvalidArgumentException('A TaskBoard user reference is a valid UTF-8 id of 1 to 128 characters, without whitespace at its edges and without control or format characters.');
        }
    }

    /**
     * A reference to a host user by id.
     *
     * @throws InvalidArgumentException when the id cannot be matched exactly
     */
    public static function ofId(string $id): self
    {
        return new self($id);
    }

    /**
     * The reference of a host user object (its `getId()`: an int, a non-empty string or a `Stringable`).
     *
     * @throws InvalidArgumentException when the user has no id that can be matched exactly
     */
    public static function of(object $user): self
    {
        if ($user instanceof self) {
            return $user;
        }

        return new self(ActorId::of($user) ?? '');
    }

    /** Whether an id can be kept and matched exactly (the rule of every TaskBoard user id). */
    public static function isValid(string $id): bool
    {
        return ExactKey::isUsable($id, self::MAX_LENGTH);
    }

    /** The id (the bundle reads user ids through `getId()`). */
    public function getId(): string
    {
        return $this->id;
    }

    /** Whether both references name the same user, byte for byte. */
    public function equals(self $other): bool
    {
        return $this->id === $other->id;
    }

    public function __toString(): string
    {
        return $this->id;
    }
}
