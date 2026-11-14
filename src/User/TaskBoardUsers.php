<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\User;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\QueryBuilder;
use InvalidArgumentException;
use Nowo\TaskBoardBundle\EventListener\TaskBoardRequestBoundarySubscriber;
use Nowo\TaskBoardBundle\Support\ActorId;
use Nowo\TaskBoardBundle\ValueObject\TaskBoardUserRef;
use Symfony\Contracts\Service\ResetInterface;

use function array_key_exists;
use function is_scalar;
use function is_string;

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

    /** Since 2.15.0 (audit OC-M2): whether a user value (a reference or a host user) has exactly this id. */
    public static function same(?object $user, string $id): bool
    {
        return $user !== null && ActorId::of($user) === $id;
    }

    /**
     * Since 2.15.0 (audit OC-M2): the id of a user the bundle erases or looks up by, refused unless it can be matched
     * exactly ({@see TaskBoardUserRef::isValid()}).
     *
     * @throws InvalidArgumentException
     */
    public static function exactId(object $user): string
    {
        $id = ActorId::of($user);
        if ($id === null || !TaskBoardUserRef::isValid($id)) {
            throw new InvalidArgumentException('TaskBoard: the user has no id that can be matched exactly (user_reference: id).');
        }

        return $id;
    }

    /**
     * Since 2.15.0 (audit OC-M2): the primary keys of the rows whose user field holds exactly `$id`. The database
     * selects the candidates (its collation may fold case, accents or trailing spaces); PHP keeps the exact ones.
     *
     * @param class-string $class
     *
     * @return list<string>
     */
    public static function exactRowIds(EntityManagerInterface $manager, string $class, string $field, string $id): array
    {
        /** @var list<array{id: mixed, ref: mixed}> $rows */
        $rows = $manager->createQueryBuilder()
            ->select('x.id AS id', 'x.' . $field . ' AS ref')
            ->from($class, 'x')
            ->where('x.' . $field . ' = :id')
            ->setParameter('id', $id)
            ->getQuery()
            ->getArrayResult();
        $ids = [];
        foreach ($rows as $row) {
            $ref = $row['ref'];
            if (($ref instanceof TaskBoardUserRef ? $ref->id : $ref) === $id) {
                $ids[] = (string) $row['id'];
            }
        }

        return $ids;
    }

    /**
     * Since 2.15.1 (audit OC Low): of the rows a database matched by user id (`taskId`, the stored `ref`), the tasks that
     * have no row whose id is exactly one of `$ids`: matched only through a look-alike (`U-BRUNO` for `u-bruno`). A query
     * keeps its candidates in SQL and drops just these, so no IN list grows with the user's tasks.
     *
     * @param iterable<array{taskId: mixed, ref: mixed}> $rows
     * @param array<string, true> $ids
     *
     * @return list<string>
     */
    public static function lookAlikeOnlyTasks(iterable $rows, array $ids): array
    {
        $seen  = [];
        $exact = [];
        foreach ($rows as $row) {
            $task = $row['taskId'];
            if (!is_scalar($task)) {
                continue;
            }
            $task        = (string) $task;
            $seen[$task] = true;
            $ref         = $row['ref'] instanceof TaskBoardUserRef ? $row['ref']->id : $row['ref'];
            if (is_string($ref) && isset($ids[$ref])) {
                $exact[$task] = true;
            }
        }

        return array_map('strval', array_keys(array_diff_key($seen, $exact)));
    }

    public function reset(): void
    {
        $this->found = [];
    }
}
