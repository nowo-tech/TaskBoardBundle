<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Doctrine\Type;

use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Types\Exception\InvalidType;
use Doctrine\DBAL\Types\StringType;
use InvalidArgumentException;
use Nowo\TaskBoardBundle\Support\ActorId;
use Nowo\TaskBoardBundle\ValueObject\TaskBoardUserRef;

use function is_object;
use function is_string;

/**
 * Since 2.15.0 (`database.user_reference: id`): the column type of the user fields of the TaskBoard rows. The database
 * holds the user's id as a string; PHP reads it back as a {@see TaskBoardUserRef}. A host user object written to a field
 * is stored by its `getId()` ({@see ActorId}), so the bundle's services can keep handing over the security user.
 *
 * @internal registered (`doctrine.dbal.types`) only with the id mode
 */
final class TaskBoardUserRefType extends StringType
{
    public const NAME = 'task_board_user_ref';

    public function convertToDatabaseValue(mixed $value, AbstractPlatform $platform): ?string
    {
        if ($value === null) {
            return null;
        }
        $id = match (true) {
            $value instanceof TaskBoardUserRef => $value->id,
            is_string($value)                  => $value,
            is_object($value)                  => ActorId::of($value),
            default                            => null,
        };
        if ($id === null) {
            throw InvalidType::new($value, self::NAME, ['null', 'string', TaskBoardUserRef::class, 'an object with getId()']);
        }
        // Audit OC-M1 (and OC-L2): checked before any write, on every platform (SQLite does not enforce the length).
        if (!TaskBoardUserRef::isValid($id)) {
            throw new InvalidArgumentException('A TaskBoard user reference is a valid UTF-8 id of 1 to 128 characters, without whitespace at its edges and without control or format characters.');
        }

        return $id;
    }

    public function convertToPHPValue(mixed $value, AbstractPlatform $platform): ?TaskBoardUserRef
    {
        return $value === null || $value === '' ? null : new TaskBoardUserRef((string) $value);
    }
}
