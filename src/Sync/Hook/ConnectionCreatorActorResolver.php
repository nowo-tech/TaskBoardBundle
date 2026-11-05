<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Sync\Hook;

use Doctrine\ORM\EntityManagerInterface;
use Nowo\TaskBoardBundle\Entity\TaskBoard;
use Nowo\TaskBoardBundle\Sync\Entity\TaskBoardConnection;
use Stringable;
use Symfony\Component\Security\Core\User\UserCheckerInterface;
use Symfony\Component\Security\Core\User\UserInterface;
use Throwable;

use function is_scalar;
use function method_exists;

/**
 * Since 1.23: the default {@see SyncActorResolverInterface} — the user named by the request (the one who pressed the
 * button) when it is the board's user class, else the user who linked the board.
 *
 * Since 1.24 (Beacon audit DATA-TASK-TOKEN-ERASURE) a run never acts as a disabled or erased account: a user whose
 * `isEnabled()` answers false, or that the `sync.actor_checker` user checker refuses in `checkPreAuth()`, is refused
 * (null, the `no_actor` path). A refused named user is not replaced by the connection's creator: deny, do not
 * substitute.
 */
final readonly class ConnectionCreatorActorResolver implements SyncActorResolverInterface
{
    /**
     * @param class-string $userClass
     * @param UserCheckerInterface|null $userChecker `sync.actor_checker` (since 1.24): the host's account checks
     */
    public function __construct(
        private EntityManagerInterface $entityManager,
        private string $userClass,
        private ?UserCheckerInterface $userChecker = null,
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
                return $this->isActive($user) ? $user : null;
            }
        }
        $creator = $this->entityManager->getRepository(TaskBoardConnection::class)->findOneBy(['board' => $board])?->getCreatedBy();

        return $creator !== null && $this->isActive($creator) ? $creator : null;
    }

    /** The id of a user, as the request carries it. */
    public static function idOf(object $user): ?string
    {
        $id = method_exists($user, 'getId') ? $user->getId() : null;

        return is_scalar($id) || $id instanceof Stringable ? (string) $id : null;
    }

    /**
     * Whether `$user` may still act: enabled (when the user class says so) and accepted by the host's user checker.
     * Any error of the checks refuses the user (fail closed).
     */
    private function isActive(object $user): bool
    {
        try {
            if (method_exists($user, 'isEnabled') && $user->isEnabled() !== true) {
                return false;
            }
            if ($this->userChecker instanceof UserCheckerInterface && $user instanceof UserInterface) {
                $this->userChecker->checkPreAuth($user);
            }
        } catch (Throwable) {
            return false;
        }

        return true;
    }
}
