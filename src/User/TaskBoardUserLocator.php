<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\User;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Nowo\TaskBoardBundle\Support\ActorId;
use Nowo\TaskBoardBundle\ValueObject\UserRef;
use Throwable;

use function ctype_digit;
use function in_array;
use function is_object;

/**
 * Since 2.29.0 (REQ-ARCH-001 rule 3): finds the host user a {@see UserRef} points at, for the id-based ports. With
 * `database.user_reference: id` through `user_directory` ({@see TaskBoardUsers::find()}); otherwise the `user_class`
 * entity, on the manager that maps it. The answer must have exactly the asked id (`getId()` byte for byte, a collation
 * never maps one id to another user); anything else, and any lookup failure, is "nobody" (null).
 *
 * Stateless apart from {@see TaskBoardUsers}' per-unit-of-work memo (cleared at every boundary): FrankenPHP worker safe.
 *
 * @internal
 */
final readonly class TaskBoardUserLocator
{
    /**
     * @param class-string $userClass `user_class`
     */
    public function __construct(
        private string $userClass,
        private TaskBoardUsers $users,
        private ?ManagerRegistry $managerRegistry = null,
    ) {
    }

    /** The host user, or null when the id names nobody. */
    public function find(UserRef $ref): ?object
    {
        try {
            $user = $this->users->idMode() ? $this->users->find($ref->id) : $this->entity($ref->id);
        } catch (Throwable) {
            return null;
        }

        return is_object($user) && ActorId::of($user) === $ref->id ? $user : null;
    }

    private function entity(string $id): ?object
    {
        $manager = $this->managerRegistry?->getManagerForClass($this->userClass);
        if (!$manager instanceof EntityManagerInterface) {
            return null;
        }
        $metadata = $manager->getClassMetadata($this->userClass);
        $type     = $metadata->getTypeOfField($metadata->getSingleIdentifierFieldName());
        if (in_array($type, ['integer', 'smallint', 'bigint'], true) && !ctype_digit($id)) {
            return null;
        }

        return $manager->find($this->userClass, $id);
    }
}
