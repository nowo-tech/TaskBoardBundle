<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Sync\Hook;

use Doctrine\ORM\EntityManagerInterface;
use Nowo\TaskBoardBundle\Entity\TaskBoard;
use Nowo\TaskBoardBundle\Sync\Entity\TaskBoardConnection;
use Stringable;
use Throwable;

use function is_scalar;
use function method_exists;

/**
 * Since 1.23: the default {@see SyncActorResolverInterface} — the user named by the request (the one who pressed the
 * button) when it is the board's user class, else the user who linked the board.
 */
final readonly class ConnectionCreatorActorResolver implements SyncActorResolverInterface
{
    /**
     * @param class-string $userClass
     */
    public function __construct(
        private EntityManagerInterface $entityManager,
        private string $userClass,
    ) {
    }

    public function actorFor(TaskBoard $board, ?string $actorId = null): ?object
    {
        if ($actorId !== null && $actorId !== '') {
            try {
                $user = $this->entityManager->find($this->userClass, $actorId);
            } catch (Throwable) {
                $user = null;
            }
            if ($user !== null) {
                return $user;
            }
        }
        $connection = $this->entityManager->getRepository(TaskBoardConnection::class)->findOneBy(['board' => $board]);

        return $connection?->getCreatedBy();
    }

    /** The id of a user, as the request carries it. */
    public static function idOf(object $user): ?string
    {
        $id = method_exists($user, 'getId') ? $user->getId() : null;

        return is_scalar($id) || $id instanceof Stringable ? (string) $id : null;
    }
}
