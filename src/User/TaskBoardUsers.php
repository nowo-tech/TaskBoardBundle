<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\User;

use Doctrine\ORM\QueryBuilder;
use Nowo\TaskBoardBundle\EventListener\TaskBoardRequestBoundarySubscriber;
use Nowo\TaskBoardBundle\Support\ActorId;
use Nowo\TaskBoardBundle\ValueObject\TaskBoardUserRef;
use Symfony\Contracts\Service\ResetInterface;

use function array_key_exists;

/**
 * Since 2.15.0 (REQ-ARCH-001 rule 2): how the TaskBoard rows reference host users, for the bundle's own queries and pages.
 *
 * - `database.user_reference: entity` (default): a Doctrine relation to `user_class`, as up to 2.14. Every method answers
 *   what 2.14 used: `IDENTITY()` in DQL, the user object as a query parameter, the user objects as they are.
 * - `id`: a string column with the user's id ({@see TaskBoardUserRef}). DQL compares the column itself, a parameter is
 *   the user's id, and a reference read back is turned into the host user through `user_directory` when a page needs a
 *   label or an avatar (exact match: an answer whose id differs byte for byte is ignored).
 *
 * The users found for references are kept for the current unit of work only: cleared at every request, Messenger message
 * and console command boundary ({@see TaskBoardRequestBoundarySubscriber}) and at
 * `kernel.reset` (REQ-RUNTIME-002).
 */
final class TaskBoardUsers implements ResetInterface
{
    public const ENTITY = 'entity';

    public const ID = 'id';

    /** @var array<string, object|null> users found for references in this unit of work, by id */
    private array $found = [];

    public function __construct(
        private readonly string $mode = self::ENTITY,
        private readonly ?TaskBoardUserDirectoryInterface $directory = null,
    ) {
    }

    public function idMode(): bool
    {
        return $this->mode === self::ID;
    }

    /** The DQL expression of a user field's value: `IDENTITY(path)` for a relation, the column itself in the id mode. */
    public function identity(string $path): string
    {
        return $this->idMode() ? $path : 'IDENTITY(' . $path . ')';
    }

    /**
     * Fetch-joins the users of a relation (one query instead of one per user), as 2.14 did; nothing in the id mode, where
     * there is no relation to join.
     */
    public function eager(QueryBuilder $builder, string $path, string $alias): QueryBuilder
    {
        if (!$this->idMode()) {
            $builder->leftJoin($path, $alias)->addSelect($alias);
        }

        return $builder;
    }

    /** A user as a DQL parameter: the object for a relation, its id in the id mode. */
    public function parameter(object $user): object|string|null
    {
        return $this->idMode() ? ActorId::of($user) : $user;
    }

    /**
     * The host user of an id through `user_directory` (id mode), checked exactly; null when nobody answers to it.
     */
    public function find(string $id): ?object
    {
        if (!array_key_exists($id, $this->found)) {
            $user = $this->directory?->find($id);
            // @igor-ignore - Per unit of work: cleared at every boundary by TaskBoardRequestBoundarySubscriber and at kernel.reset.
            $this->found[$id] = $user !== null && ActorId::of($user) === $id ? $user : null;
        }

        return $this->found[$id];
    }

    /**
     * What a page shows for a user field: the host user behind a {@see TaskBoardUserRef} when the directory knows it,
     * else the value as it is (the reference shows its id; anything else is returned untouched).
     */
    public function resolve(?object $user): ?object
    {
        if (!$user instanceof TaskBoardUserRef) {
            return $user;
        }

        return $this->find($user->id) ?? $user;
    }

    public function reset(): void
    {
        $this->found = [];
    }
}
