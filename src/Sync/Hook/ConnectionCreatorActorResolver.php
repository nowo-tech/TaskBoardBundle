<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Sync\Hook;

use Doctrine\ORM\EntityManagerInterface;
use Nowo\TaskBoardBundle\Entity\TaskBoard;
use Nowo\TaskBoardBundle\Sync\Entity\TaskBoardConnection;
use Nowo\TaskBoardBundle\User\TaskBoardUsers;
use Nowo\TaskBoardBundle\ValueObject\TaskBoardSnapshot;
use Stringable;
use Symfony\Component\Security\Core\User\UserCheckerInterface;
use Symfony\Component\Security\Core\User\UserInterface;
use Throwable;

use function is_scalar;
use function method_exists;

/**
 * Since 1.23: the default {@see SyncActorResolverInterface} — since 2.15.1 also the default
 * {@see SyncActorProviderInterface}, aliased under that interface so a host can autowire it — the user named by the request (the one who pressed the
 * button), else (no named user) the user who linked the board.
 *
 * Since 1.24 (Beacon audit DATA-TASK-TOKEN-ERASURE) a run never acts as a disabled or erased account: a user whose
 * `isEnabled()` answers false, or that the `sync.actor_checker` user checker refuses in `checkPreAuth()`, is refused
 * (null, the `no_actor` path). A refused named user — disabled, refused, no longer found, or whose lookup fails — is
 * not replaced by the connection's creator: deny, do not substitute. Only a run without a named actor acts as the
 * user who linked the board.
 */
final readonly class ConnectionCreatorActorResolver implements SyncActorResolverInterface, SyncActorProviderInterface
{
    /**
     * @param class-string $userClass
     * @param UserCheckerInterface|null $userChecker `sync.actor_checker` (since 1.24): the host's account checks
     */
    public function __construct(
        private EntityManagerInterface $entityManager,
        private string $userClass,
        private ?UserCheckerInterface $userChecker = null,
        // Since 2.15.0 (database.user_reference).
        private ?TaskBoardUsers $users = null,
    ) {
    }

    /**
     * Since 2.15.1 the board may also come as a {@see TaskBoardSnapshot} ({@see SyncActorProviderInterface}, autowirable
     * under that interface): same answer as for the entity, the connection is found by the board's id.
     */
    public function actorFor(TaskBoard|TaskBoardSnapshot $board, ?string $actorId = null): ?object
    {
        if ($actorId !== null && $actorId !== '') {
            // Audit EX-L2: a named actor that no longer resolves (deleted, erased) or whose lookup fails is refused;
            // the user who linked the board never acts in their place.
            try {
                // Since 2.15.0: through user_directory with user_reference: id (user_class is no Doctrine entity).
                $user = $this->users instanceof TaskBoardUsers && $this->users->idMode() ? $this->users->find($actorId) : $this->entityManager->find($this->userClass, $actorId);
            } catch (Throwable) {
                return null;
            }

            return $user !== null && $this->isActive($user) ? $user : null;
        }
        $creator = $this->entityManager->getRepository(TaskBoardConnection::class)->findOneBy(['board' => $board instanceof TaskBoard ? $board : $board->id])?->getCreatedBy();

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
