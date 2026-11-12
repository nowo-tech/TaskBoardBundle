<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Doctrine\Type;

use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Types\Exception\InvalidType;
use Doctrine\DBAL\Types\StringType;
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
        if ($value === null || is_string($value)) {
            return $value;
        }
        if ($value instanceof TaskBoardUserRef) {
            return $value->id;
        }
        $id = is_object($value) ? ActorId::of($value) : null;
        if ($id === null) {
            throw InvalidType::new($value, self::NAME, ['null', 'string', TaskBoardUserRef::class, 'an object with getId()']);
        }

        return $id;
    }

    public function convertToPHPValue(mixed $value, AbstractPlatform $platform): ?TaskBoardUserRef
    {
        return $value === null || $value === '' ? null : new TaskBoardUserRef((string) $value);
    }
}
